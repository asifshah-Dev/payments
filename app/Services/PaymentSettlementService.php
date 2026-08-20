<?php

namespace App\Services;

use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentSettlementService
{
    public function __construct(
        private LedgerPostingService $ledgerPostingService,
        private LedgerAccountResolver $ledgerAccountResolver,
    ) {
    }

    public function settle(
        PaymentAttempt $paymentAttempt
    ): LedgerTransaction {
        return DB::transaction(function () use ($paymentAttempt) {

            /*
             * Only succeeded payment attempts can be settled.
             */
            if ($paymentAttempt->status !== 'succeeded') {
                throw new RuntimeException(
                    'Only succeeded payment attempts can be settled.'
                );
            }

            /*
             * Idempotency:
             * If this payment attempt has already been settled,
             * return the existing ledger transaction.
             */
            $existing = LedgerTransaction::query()
                ->where('payment_attempt_id', $paymentAttempt->id)
                ->where('type', 'payment')
                ->first();

            if ($existing) {
                return $existing;
            }

            /*
             * Resolve exactly one active asset account
             * and exactly one active liability account
             * for the payment currency.
             */
            $accounts = $this->ledgerAccountResolver->resolve(
                $paymentAttempt->currency
            );

            /*
             * Create balanced ledger posting:
             *
             * Debit  -> Clearing / Asset
             * Credit -> Merchant Payable / Liability
             */
            return $this->ledgerPostingService->post(
                type: 'payment',
                amount: $paymentAttempt->amount,
                currency: $paymentAttempt->currency,
                direction: 'credit',
                paymentAttemptId: $paymentAttempt->id,
                referenceType: 'payment_attempt',
                referenceId: $paymentAttempt->id,
                description: 'Payment settled',
                entries: [
                    [
                        'ledger_account_id' => $accounts['debit']->id,
                        'type' => 'debit',
                        'amount' => $paymentAttempt->amount,
                        'currency' => $paymentAttempt->currency,
                    ],
                    [
                        'ledger_account_id' => $accounts['credit']->id,
                        'type' => 'credit',
                        'amount' => $paymentAttempt->amount,
                        'currency' => $paymentAttempt->currency,
                    ],
                ],
            );
        });
    }
}