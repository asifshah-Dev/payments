<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentRecoveryManager
{
    public function attemptPosting(int $paymentId, bool $shouldFail = false): bool
    {
        if ($shouldFail && !DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->exists()) {
            return false;
        }

        $payment = DB::table('recovery_payments')->where('id', $paymentId)->first();
        if (!$payment || $payment->status !== 'succeeded') {
            return false;
        }

        return DB::transaction(function () use ($payment) {
            $exists = DB::table('recovery_ledger_transactions')->where('payment_id', $payment->id)->exists();
            if ($exists) {
                return true;
            }

            DB::table('recovery_ledger_transactions')->insert([
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'direction' => 'debit',
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    public function postAtomicallyWithForcedFailure(int $paymentId): void
    {
        DB::beginTransaction();

        try {
            $payment = DB::table('recovery_payments')
                ->where('id', $paymentId)
                ->first();

            DB::table('recovery_ledger_transactions')->insert([
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'direction' => 'debit',
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            throw new RuntimeException('FORCED FAILURE');
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    public function getStuckPayments(): Collection
    {
        return DB::table('recovery_payments')->where('status', 'stuck')->get();
    }

    public function recoverStuckPayment(int $paymentId): bool
    {
        return DB::transaction(function () use ($paymentId) {
            $payment = DB::table('recovery_payments')->where('id', $paymentId)->first();
            if (!$payment) {
                return false;
            }

            $exists = DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->exists();
            if (!$exists) {
                DB::table('recovery_ledger_transactions')->insert([
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'direction' => 'debit',
                    'posted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
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