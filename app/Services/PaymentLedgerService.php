<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentLedgerService
{
    public function __construct(
        private LedgerPostingService $ledgerPostingService
    ) {
    }

    public function postPayment(
        PaymentAttempt $paymentAttempt,
        LedgerAccount $debitAccount,
        LedgerAccount $creditAccount,
    ): LedgerTransaction {
        return DB::transaction(function () use (
            $paymentAttempt,
            $debitAccount,
            $creditAccount
        ) {
            // Payment must be successful before it can be posted.
            if ($paymentAttempt->status !== 'succeeded') {
                throw new RuntimeException(
                    'Only succeeded payment attempts can be posted to the ledger.'
                );
            }

            // Both ledger accounts must use the same currency.
            if ($debitAccount->currency !== $creditAccount->currency) {
                throw new RuntimeException(
                    'Debit and credit ledger accounts must use the same currency.'
                );
            }

            // Ledger account currency must match the payment currency.
            if ($debitAccount->currency !== $paymentAttempt->currency) {
                throw new RuntimeException(
                    'Ledger account currency must match payment attempt currency.'
                );
            }

            // Prevent duplicate posting for this payment attempt.
            $existing = LedgerTransaction::query()
                ->where('payment_attempt_id', $paymentAttempt->id)
                ->where('type', 'payment')
                ->first();

            if ($existing) {
                return $existing;
            }

            return $this->ledgerPostingService->post(
                type: 'payment',
                amount: $paymentAttempt->amount,
                currency: $paymentAttempt->currency,
                direction: 'credit',
                paymentAttemptId: $paymentAttempt->id,
                referenceType: 'payment_attempt',
                referenceId: $paymentAttempt->id,
                description: 'Payment received',
                entries: [
                    [
                        'ledger_account_id' => $debitAccount->id,
                        'type' => 'debit',
                        'amount' => $paymentAttempt->amount,
                        'currency' => $paymentAttempt->currency,
                    ],
                    [
                        'ledger_account_id' => $creditAccount->id,
                        'type' => 'credit',
                        'amount' => $paymentAttempt->amount,
                        'currency' => $paymentAttempt->currency,
                    ],
                ],
            );
        });
    }
}