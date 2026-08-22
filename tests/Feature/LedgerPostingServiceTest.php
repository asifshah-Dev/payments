<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LedgerPostingService
{
    /**
     * Post a transaction to the ledger with double-entry validation.
     */
    public function post(
        string $type,
        int $amount,
        string $currency,
        string $direction,
        array $entries,
        ?string $paymentAttemptId = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $description = null
    ): LedgerTransaction {
        return DB::transaction(function () use (
            $type,
            $amount,
            $currency,
            $direction,
            $entries,
            $paymentAttemptId,
            $referenceType,
            $referenceId,
            $description
        ) {
            // 1. Prevent duplicate ledger posting for the same payment attempt
            if ($paymentAttemptId !== null) {
                $exists = LedgerTransaction::where('payment_attempt_id', $paymentAttemptId)->exists();
                if ($exists) {
                    throw new RuntimeException("A ledger transaction for this payment attempt already exists.");
                }
            }

            // 2. Validate transaction amount
            if ($amount <= 0) {
                throw new InvalidArgumentException("Transaction amount must be greater than zero.");
            }

            // 3. Pre-validate individual entry rules
            $hasDebit = false;
            $hasCredit = false;
            $totalDebits = 0;
            $totalCredits = 0;

            foreach ($entries as $entryData) {
                // Validate entry type
                if (!isset($entryData['type']) || !in_array($entryData['type'], ['debit', 'credit'])) {
                    throw new InvalidArgumentException("Invalid ledger entry type.");
                }

                // Validate entry amount
                if (!isset($entryData['amount']) || $entryData['amount'] <= 0) {
                    throw new InvalidArgumentException("Ledger entry amount must be greater than zero.");
                }

                // Validate currency consistency
                if (!isset($entryData['currency']) || $entryData['currency'] !== $currency) {
                    throw new InvalidArgumentException("Ledger entry currency must match transaction currency.");
                }

                // Validate account existence
                if (!isset($entryData['ledger_account_id']) || !LedgerAccount::where('id', $entryData['ledger_account_id'])->exists()) {
                    throw new RuntimeException("Ledger account does not exist.");
                }

                if ($entryData['type'] === 'debit') {
                    $hasDebit = true;
                    $totalDebits += $entryData['amount'];
                } else {
                    $hasCredit = true;
                    $totalCredits += $entryData['amount'];
                }
            }

            if (!$hasDebit || !$hasCredit) {
                throw new InvalidArgumentException("Transaction must have at least one debit and one credit entry.");
            }

            if ($totalDebits !== $totalCredits) {
                throw new InvalidArgumentException("Total debits must equal total credits.");
            }

            // 4. Create the parent Ledger Transaction
            $transaction = LedgerTransaction::create([
                'payment_attempt_id' => $paymentAttemptId,
                'type' => $type,
                'amount' => $amount,
                'currency' => $currency,
                'direction' => $direction,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'posted_at' => null,
            ]);

            // 5. Attach the Ledger Entries
            foreach ($entries as $entryData) {
                $transaction->entries()->create($entryData);
            }

            // 6. Finalize and post atomically
            $transaction->post();

            return $transaction;
        });
    }
}