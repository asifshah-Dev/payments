<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use RuntimeException;
use InvalidArgumentException;

class PaymentRecoveryManager
{
    public function createPaymentIntent(array $data): int
    {
        return DB::table('payment_intents')->insertGetId([
            'merchant_id' => $data['merchant_id'] ?? 1,
            'status' => $data['status'] ?? 'pending',
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'attempts_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function updatePaymentIntentStatus(int $intentId, string $newStatus): bool
    {
        return DB::transaction(function () use ($intentId, $newStatus) {
            $intent = DB::table('payment_intents')->where('id', $intentId)->lockForUpdate()->first();
            if (!$intent) {
                throw new InvalidArgumentException('Payment intent not found.');
            }

            $current = $intent->status;

            if ($current === $newStatus) {
                return true; // Idempotent
            }

            $allowedTransitions = [
                'pending' => ['processing', 'cancelled'],
                'created' => ['processing', 'cancelled'],
                'processing' => ['succeeded', 'failed'],
                'succeeded' => [],
                'failed' => ['processing'], // Allow retry if business rule permits
                'cancelled' => [],
            ];

            if (!isset($allowedTransitions[$current]) || !in_array($newStatus, $allowedTransitions[$current])) {
                throw new InvalidArgumentException("Invalid state transition from {$current} to {$newStatus}.");
            }

            DB::table('payment_intents')->where('id', $intentId)->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

            if ($newStatus === 'succeeded') {
                // Automatically ensure ledger posting for integration invariant
                $this->attemptPostingFromIntent($intentId);
            }

            return true;
        });
    }

    public function updateIntentDetails(int $intentId, array $data): bool
    {
        return DB::transaction(function () use ($intentId, $data) {
            $intent = DB::table('payment_intents')->where('id', $intentId)->lockForUpdate()->first();
            if (!$intent) {
                throw new InvalidArgumentException('Payment intent not found.');
            }

            if (in_array($intent->status, ['processing', 'succeeded', 'failed', 'cancelled'])) {
                if (isset($data['amount']) && (int)$data['amount'] !== (int)$intent->amount) {
                    throw new InvalidArgumentException('Payment amount cannot change after processing begins.');
                }
                if (isset($data['currency']) && $data['currency'] !== $intent->currency) {
                    throw new InvalidArgumentException('Payment currency cannot change after processing begins.');
                }
            }

            DB::table('payment_intents')->where('id', $intentId)->update(array_merge($data, ['updated_at' => now()]));
            return true;
        });
    }

    public function attemptPostingFromIntent(int $intentId): bool
    {
        return DB::transaction(function () use ($intentId) {
            $intent = DB::table('payment_intents')->where('id', $intentId)->lockForUpdate()->first();
            if (!$intent || $intent->status !== 'succeeded') {
                return false;
            }

            // Also check/map to recovery_payments if needed for compatibility
            $paymentId = DB::table('recovery_payments')->where('id', $intentId)->exists() 
                ? $intentId 
                : DB::table('recovery_payments')->insertGetId([
                    'id' => $intentId,
                    'merchant_id' => $intent->merchant_id,
                    'status' => 'succeeded',
                    'amount' => $intent->amount,
                    'currency' => $intent->currency,
                    'created_at' => now(),
                    'updated_at' => now(),
                  ]);

            return $this->attemptPosting($paymentId, false);
        });
    }

    public function verifyReconciliation(int $intentId): bool
    {
        $intent = DB::table('payment_intents')->where('id', $intentId)->first();
        if (!$intent) {
            return false;
        }

        if ($intent->status === 'succeeded') {
            $ledgerEntry = DB::table('recovery_ledger_transactions')
                ->where('payment_id', $intentId)
                ->where('type', 'capture')
                ->first();

            return $ledgerEntry && ((int)$ledgerEntry->amount === (int)$intent->amount);
        }

        if (in_array($intent->status, ['failed', 'cancelled', 'pending', 'created'])) {
            $ledgerEntry = DB::table('recovery_ledger_transactions')
                ->where('payment_id', $intentId)
                ->where('type', 'capture')
                ->exists();

            return !$ledgerEntry;
        }

        return true;
    }

    public function attemptPosting(int $paymentId, bool $shouldFail = false): bool
    {
        if ($shouldFail && !DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->exists()) {
            return false;
        }

        return DB::transaction(function () use ($paymentId) {
            $payment = DB::table('recovery_payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (!$payment || $payment->status !== 'succeeded') {
                return false;
            }

            $exists = DB::table('recovery_ledger_transactions')
                ->where('payment_id', $payment->id)
                ->where('type', 'capture')
                ->exists();

            if ($exists) {
                return true;
            }

            try {
                DB::table('recovery_ledger_transactions')->insert([
                    'payment_id' => $payment->id,
                    'merchant_id' => $payment->merchant_id ?? 1,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'direction' => 'debit',
                    'type' => 'capture',
                    'posted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                    return true;
                }
                throw $e;
            }

            return true;
        });
    }

    public function postLedgerEntry(int $paymentId, string $type, string $direction, int $amount, string $currency = 'USD', int $merchantId = 1): bool
    {
        return DB::transaction(function () use ($paymentId, $type, $direction, $amount, $currency, $merchantId) {
            DB::table('recovery_ledger_transactions')->insert([
                'payment_id' => $paymentId,
                'merchant_id' => $merchantId,
                'amount' => $amount,
                'currency' => $currency,
                'direction' => $direction,
                'type' => $type,
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return true;
        });
    }

    public function calculateBalance(int $merchantId, string $currency = 'USD'): int
    {
        $transactions = DB::table('recovery_ledger_transactions')
            ->where('merchant_id', $merchantId)
            ->where('currency', $currency)
            ->get();

        $balance = 0;
        foreach ($transactions as $tx) {
            if ($tx->direction === 'debit') {
                $balance += (int) $tx->amount;
            } else {
                $balance -= (int) $tx->amount;
            }
        }

        return $balance;
    }

    public function postRefund(int $paymentId, int $refundAmount): bool
    {
        return DB::transaction(function () use ($paymentId, $refundAmount) {
            $payment = DB::table('recovery_payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (!$payment || !in_array($payment->status, ['succeeded', 'refunded', 'partial_refunded'])) {
                throw new InvalidArgumentException('Invalid payment status for refund.');
            }

            $totalRefunded = (int) DB::table('recovery_ledger_transactions')
                ->where('payment_id', $paymentId)
                ->where('type', 'refund')
                ->sum('amount');

            if (($totalRefunded + $refundAmount) > $payment->amount) {
                throw new InvalidArgumentException('Refund amount exceeds original payment amount.');
            }

            DB::table('recovery_ledger_transactions')->insert([
                'payment_id' => $payment->id,
                'merchant_id' => $payment->merchant_id ?? 1,
                'amount' => $refundAmount,
                'currency' => $payment->currency,
                'direction' => 'credit',
                'type' => 'refund',
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $newStatus = (($totalRefunded + $refundAmount) === $payment->amount) ? 'refunded' : 'partial_refunded';
            DB::table('recovery_payments')->where('id', $paymentId)->update(['status' => $newStatus]);

            return true;
        });
    }

    public function postChargeback(int $paymentId): bool
    {
        return DB::transaction(function () use ($paymentId) {
            $payment = DB::table('recovery_payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (!$payment || !in_array($payment->status, ['succeeded', 'partial_refunded'])) {
                throw new InvalidArgumentException('Payment must be succeeded to chargeback.');
            }

            $exists = DB::table('recovery_ledger_transactions')
                ->where('payment_id', $paymentId)
                ->where('type', 'chargeback')
                ->exists();

            if ($exists) {
                return true;
            }

            DB::table('recovery_ledger_transactions')->insert([
                'payment_id' => $payment->id,
                'merchant_id' => $payment->merchant_id ?? 1,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'direction' => 'credit',
                'type' => 'chargeback',
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('recovery_payments')->where('id', $paymentId)->update(['status' => 'chargeback']);

            return true;
        });
    }

    public function postChargebackReversal(int $paymentId): bool
    {
        return DB::transaction(function () use ($paymentId) {
            $payment = DB::table('recovery_payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (!$payment || $payment->status !== 'chargeback') {
                throw new InvalidArgumentException('Payment must be in chargeback status to reverse.');
            }

            DB::table('recovery_ledger_transactions')->insert([
                'payment_id' => $payment->id,
                'merchant_id' => $payment->merchant_id ?? 1,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'direction' => 'debit',
                'type' => 'chargeback_reversal',
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('recovery_payments')->where('id', $paymentId)->update(['status' => 'succeeded']);

            return true;
        });
    }
}