<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use RuntimeException;
use InvalidArgumentException;

class PaymentRecoveryManager
{
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
            if (!$payment || $payment->status !== 'succeeded') {
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

    public function markSucceededAtomically(int $paymentId): bool
    {
        return DB::transaction(function () use ($paymentId) {
            $payment = DB::table('recovery_payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (!$payment) {
                throw new InvalidArgumentException('Payment not found.');
            }

            $captureExists = DB::table('recovery_ledger_transactions')
                ->where('payment_id', $paymentId)
                ->where('type', 'capture')
                ->where('amount', $payment->amount)
                ->exists();

            if (!$captureExists) {
                throw new RuntimeException('Cannot mark payment succeeded without required matching ledger posting.');
            }

            DB::table('recovery_payments')->where('id', $paymentId)->update(['status' => 'succeeded']);
            return true;
        });
    }

    public function validateConsistency(int $paymentId): bool
    {
        $payment = DB::table('recovery_payments')->where('id', $paymentId)->first();
        if (!$payment) {
            return false;
        }

        if ($payment->status === 'succeeded') {
            $capture = DB::table('recovery_ledger_transactions')
                ->where('payment_id', $paymentId)
                ->where('type', 'capture')
                ->first();

            return $capture && ((int) $capture->amount === (int) $payment->amount);
        }

        return true;
    }

    public function postAtomicallyWithForcedFailure(int $paymentId): void
    {
        DB::transaction(function () use ($paymentId) {
            $payment = DB::table('recovery_payments')
                ->where('id', $paymentId)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                throw new RuntimeException('Payment not found');
            }

            DB::table('recovery_ledger_transactions')->insert([
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'direction' => 'debit',
                'type' => 'capture',
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            throw new RuntimeException('Forced failure midway');
        });
    }

    public function getStuckPayments(): Collection
    {
        return DB::table('recovery_payments')->where('status', 'stuck')->get();
    }

    public function recoverStuckPayment(int $paymentId): bool
    {
        return DB::transaction(function () use ($paymentId) {
            $payment = DB::table('recovery_payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (!$payment) {
                return false;
            }

            $exists = DB::table('recovery_ledger_transactions')
                ->where('payment_id', $paymentId)
                ->where('type', 'capture')
                ->exists();

            if (!$exists) {
                try {
                    DB::table('recovery_ledger_transactions')->insert([
                        'payment_id' => $payment->id,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'direction' => 'debit',
                        'type' => 'capture',
                        'posted_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (QueryException $e) {
                    if ($e->getCode() !== '23000' && !str_contains($e->getMessage(), 'Duplicate entry')) {
                        throw $e;
                    }
                }
            }

            DB::table('recovery_payments')->where('id', $paymentId)->update(['status' => 'succeeded']);

            return true;
        });
    }

    public function handleOutOfOrderPosting(int $paymentId): bool
    {
        $payment = DB::table('recovery_payments')->where('id', $paymentId)->first();
        if (!$payment || $payment->status !== 'succeeded') {
            return false;
        }

        return $this->attemptPosting($paymentId, false);
    }

    public function processLatePosting(int $paymentId): bool
    {
        return $this->recoverStuckPayment($paymentId);
    }
}