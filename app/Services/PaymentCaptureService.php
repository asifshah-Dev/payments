<?php

namespace App\Services;

use App\Models\PaymentAttempt;
use App\Models\LedgerTransaction;
use App\Models\LedgerEntry;
use App\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;
use Exception;

class PaymentCaptureService
{
    public function capture(PaymentAttempt $attempt): LedgerTransaction
    {
        return DB::transaction(function () use ($attempt) {
            // 1. Lock the payment attempt row to prevent concurrent worker execution
            $lockedAttempt = PaymentAttempt::where('id', $attempt->id)->lockForUpdate()->first();

            // 2. Prevent duplicate capture if already succeeded
            if ($lockedAttempt->status === 'succeeded') {
                throw new Exception("Payment attempt has already been captured.");
            }

            $amount = $lockedAttempt->amount;
            $currency = $lockedAttempt->currency;

            // 3. Create the primary Ledger Transaction header with explicit currency
            $ledgerTransaction = LedgerTransaction::create([
                'payment_attempt_id' => $lockedAttempt->id,
                'type' => 'payment_capture',
                'currency' => $currency,
                'posted_at' => now(),
                'description' => "Capture for payment attempt {$lockedAttempt->id}",
            ]);

            // 4. Explicitly resolve pre-provisioned accounts matching currency and status
            $clearingAccountId = $this->getGatewayClearingAccountId($lockedAttempt->processor, $currency);
            $pendingAccountId = $this->getMerchantPendingAccountId($lockedAttempt->paymentIntent->merchant_id ?? null, $currency);

            // 5. Define Entries
            $debitEntry = [
                'ledger_transaction_id' => $ledgerTransaction->id,
                'ledger_account_id' => $clearingAccountId,
                'type' => 'debit',
                'amount' => $amount,
                'currency' => $currency,
            ];

            $creditEntry = [
                'ledger_transaction_id' => $ledgerTransaction->id,
                'ledger_account_id' => $pendingAccountId,
                'type' => 'credit',
                'amount' => $amount,
                'currency' => $currency,
            ];

            // 6. Guarantee Debits == Credits strictly before persisting
            if ($debitEntry['amount'] !== $creditEntry['amount']) {
                throw new Exception("Ledger imbalance: Total debits must equal total credits.");
            }

            LedgerEntry::create($debitEntry);
            LedgerEntry::create($creditEntry);

            // 7. Finalize payment attempt status update
            $lockedAttempt->update(['status' => 'succeeded']);

            return $ledgerTransaction;
        });
    }

    protected function getGatewayClearingAccountId(
    string $processor,
    string $currency
): string|int
{
    $account = LedgerAccount::where('type', 'asset')
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

    protected function getMerchantPendingAccountId(?string $merchantId, string $currency): string|int
{
    if (!$merchantId) {
        throw new Exception("Payment attempt has no merchant.");
    }

    $account = LedgerAccount::where('type', 'liability')
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
}