<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use Exception;
use Illuminate\Support\Facades\DB;

class PaymentCaptureService
{
    public function capture(PaymentAttempt $attempt): LedgerTransaction
    {
        return DB::transaction(function () use ($attempt) {

            /*
             * 1. Lock the payment attempt row.
             *
             * This prevents two concurrent workers from capturing
             * the same payment attempt at the same time.
             */
            $lockedAttempt = PaymentAttempt::query()
                ->where('id', $attempt->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedAttempt) {
                throw new Exception(
                    "Payment attempt [{$attempt->id}] not found."
                );
            }

            /*
             * 2. Validate payment attempt state.
             */

            if ($lockedAttempt->status === 'succeeded') {
                throw new Exception(
                    'Payment attempt has already been captured.'
                );
            }

            if (in_array($lockedAttempt->status, ['failed', 'cancelled'], true)) {
                throw new Exception(
                    "Cannot capture a payment attempt with status [{$lockedAttempt->status}]."
                );
            }

            if (!in_array($lockedAttempt->status, ['pending', 'processing'], true)) {
                throw new Exception(
                    "Invalid payment attempt status [{$lockedAttempt->status}] for capture."
                );
            }

            /*
             * 3. IMPORTANT:
             *
             * The payment attempt is the source of truth for
             * capture amount and currency.
             */
            $amount = (int) $lockedAttempt->amount;
            $currency = strtoupper($lockedAttempt->currency);

            if ($amount <= 0) {
                throw new Exception(
                    "Cannot capture a payment attempt with invalid amount [{$amount}]."
                );
            }

            /*
             * 4. Calculate platform fee.
             *
             * 2% fee, rounded down to the smallest currency unit.
             */
            $fee = $this->calculatePlatformFee($amount);

            $merchantAmount = $amount - $fee;

            /*
             * Safety check.
             */
            if ($merchantAmount < 0) {
                throw new Exception(
                    "Calculated merchant amount cannot be negative."
                );
            }

            /*
             * 5. Resolve all required ledger accounts.
             */

            $clearingAccountId = $this->getGatewayClearingAccountId(
                $lockedAttempt->processor,
                $currency
            );

            $merchantId = $lockedAttempt->paymentIntent?->merchant_id;

            $pendingAccountId = $this->getMerchantPendingAccountId(
                $merchantId,
                $currency
            );

            $feeRevenueAccountId = $this->getPlatformFeeRevenueAccountId(
                $currency
            );

            /*
             * 6. Verify the accounting equation before creating anything.
             *
             * Debit:
             *     Gateway Clearing = amount
             *
             * Credits:
             *     Merchant Pending = amount - fee
             *     Platform Fee Revenue = fee
             *
             * Therefore:
             *
             *     amount = (amount - fee) + fee
             */
            $totalDebits = $amount;

            $totalCredits = $merchantAmount + $fee;

            if ($totalDebits !== $totalCredits) {
                throw new Exception(
                    "Ledger imbalance: Total debits [{$totalDebits}] must equal total credits [{$totalCredits}]."
                );
            }

            /*
             * 7. Create the ledger transaction header.
             *
             * IMPORTANT:
             * `amount` MUST be included because the database
             * column is NOT NULL.
             */
            $ledgerTransaction = LedgerTransaction::create([
                'payment_attempt_id' => $lockedAttempt->id,
                'type' => 'payment_capture',
                'amount' => $amount,
                'direction' => 'credit',
                'currency' => $currency,
                'posted_at' => now(),
                'description' => "Capture for payment attempt {$lockedAttempt->id}",
            ]);

            /*
             * 8. Create debit entry.
             */
            LedgerEntry::create([
                'ledger_transaction_id' => $ledgerTransaction->id,
                'ledger_account_id' => $clearingAccountId,
                'type' => 'debit',
                'amount' => $amount,
                'currency' => $currency,
            ]);

            /*
             * 9. Create merchant pending credit entry.
             */
            LedgerEntry::create([
                'ledger_transaction_id' => $ledgerTransaction->id,
                'ledger_account_id' => $pendingAccountId,
                'type' => 'credit',
                'amount' => $merchantAmount,
                'currency' => $currency,
            ]);

            /*
             * 10. Create platform fee revenue credit entry.
             */
            LedgerEntry::create([
                'ledger_transaction_id' => $ledgerTransaction->id,
                'ledger_account_id' => $feeRevenueAccountId,
                'type' => 'credit',
                'amount' => $fee,
                'currency' => $currency,
            ]);

            /*
             * 11. Mark the payment attempt as successfully captured.
             */
            $lockedAttempt->update([
                'status' => 'succeeded',
            ]);

            /*
             * 12. Return the ledger transaction.
             */
           return $ledgerTransaction->load('entries');
        });
    }

    protected function getGatewayClearingAccountId(
        string $processor,
        string $currency
    ): string|int {
        $account = LedgerAccount::query()
            ->where('type', 'asset')
            ->where('currency', $currency)
            ->where('status', 'active')
            ->where('name', "Gateway Clearing - {$processor}")
            ->first();

        if (!$account) {
            throw new Exception(
                "Active Gateway Clearing account not found for processor [{$processor}] and currency [{$currency}]."
            );
        }

        return $account->id;
    }

    protected function getMerchantPendingAccountId(
        ?string $merchantId,
        string $currency
    ): string|int {
        if (!$merchantId) {
            throw new Exception(
                'Payment attempt has no merchant.'
            );
        }

        $account = LedgerAccount::query()
            ->where('type', 'liability')
            ->where('currency', $currency)
            ->where('status', 'active')
            ->where('name', "Merchant Pending - {$merchantId}")
            ->first();

        if (!$account) {
            throw new Exception(
                "Active Merchant Pending account not found for merchant [{$merchantId}] and currency [{$currency}]."
            );
        }

        return $account->id;
    }

    protected function calculatePlatformFee(int $amount): int
    {
        /*
         * 2% fee.
         *
         * intdiv() intentionally rounds down to the smallest
         * currency unit.
         */
        return intdiv($amount * 2, 100);
    }

    protected function getPlatformFeeRevenueAccountId(
        string $currency
    ): string|int {
        $account = LedgerAccount::query()
            ->where('type', 'revenue')
            ->where('currency', $currency)
            ->where('status', 'active')
            ->where('name', "Platform Fee Revenue - {$currency}")
            ->first();

        if (!$account) {
            throw new Exception(
                "Active Platform Fee Revenue account not found for currency [{$currency}]."
            );
        }

        return $account->id;
    }
}