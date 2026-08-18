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

            // 2. Enforce strict state machine rules
            if ($lockedAttempt->status === 'succeeded') {
                throw new Exception("Payment attempt has already been captured.");
            }

            if (in_array($lockedAttempt->status, ['failed', 'cancelled'])) {
                throw new Exception("Cannot capture a payment attempt with status [{$lockedAttempt->status}].");
            }

            if (!in_array($lockedAttempt->status, ['pending', 'processing'])) {
                throw new Exception("Invalid payment attempt status [{$lockedAttempt->status}] for capture.");
            }

           $amount = $lockedAttempt->amount;
$currency = $lockedAttempt->currency;

if ($amount <= 0) {
    throw new Exception(
        "Cannot capture a payment attempt with invalid amount [{$amount}]."
    );
}

$fee = $this->calculatePlatformFee($amount);
$merchantAmount = $amount - $fee;

            // 3. Resolve accounts
            $clearingAccountId = $this->getGatewayClearingAccountId($lockedAttempt->processor, $currency);
            $pendingAccountId = $this->getMerchantPendingAccountId($lockedAttempt->paymentIntent->merchant_id ?? null, $currency);
            $feeRevenueAccountId = $this->getPlatformFeeRevenueAccountId($currency);

            // 4. Create the primary Ledger Transaction header
            $ledgerTransaction = LedgerTransaction::create([
                'payment_attempt_id' => $lockedAttempt->id,
                'type' => 'payment_capture',
                'currency' => $currency,
                'posted_at' => now(),
                'description' => "Capture for payment attempt {$lockedAttempt->id}",
            ]);

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
                'amount' => $merchantAmount,
                'currency' => $currency,
            ];

            $feeCreditEntry = [
                'ledger_transaction_id' => $ledgerTransaction->id,
                'ledger_account_id' => $feeRevenueAccountId,
                'type' => 'credit',
                'amount' => $fee,
                'currency' => $currency,
            ];

            // 6. Guarantee Total Debits == Total Credits strictly before persisting
            $totalDebits = $debitEntry['amount'];
            $totalCredits = $creditEntry['amount'] + $feeCreditEntry['amount'];

            if ($totalDebits !== $totalCredits) {
                throw new Exception("Ledger imbalance: Total debits [{$totalDebits}] must equal total credits [{$totalCredits}].");
            }

            // 7. Persist all entries
            LedgerEntry::create($debitEntry);
            LedgerEntry::create($creditEntry);
            LedgerEntry::create($feeCreditEntry);

            // 8. Finalize payment attempt status update
            $lockedAttempt->update(['status' => 'succeeded']);

            return $ledgerTransaction;
        });
    }

    protected function getGatewayClearingAccountId(string $processor, string $currency): string|int
    {
        $account = LedgerAccount::where('type', 'asset')
            ->where('currency', $currency)
            ->where('status', 'active')
            ->where('name', "Gateway Clearing - {$processor}")
            ->first();

        if (!$account) {
            throw new Exception("Active Gateway Clearing account not found for processor [{$processor}] and currency [{$currency}].");
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
            throw new Exception("Active Merchant Pending account not found for merchant [{$merchantId}] and currency [{$currency}].");
        }

        return $account->id;
    }

    protected function calculatePlatformFee(int $amount): int
    {
        return intdiv($amount * 2, 100);
    }

    protected function getPlatformFeeRevenueAccountId(string $currency): string|int
    {
        $account = LedgerAccount::where('type', 'revenue')
            ->where('currency', $currency)
            ->where('status', 'active')
            ->where('name', "Platform Fee Revenue - {$currency}")
            ->first();

        if (!$account) {
            throw new Exception("Active Platform Fee Revenue account not found for currency [{$currency}].");
        }

        return $account->id;
    }
}