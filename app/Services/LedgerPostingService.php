<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LedgerPostingService
{
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
        $this->validateTransaction(
            $amount,
            $currency,
            $direction,
            $entries
        );

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
            /*
             * A payment attempt must not be posted to the ledger twice.
             */
            if ($paymentAttemptId !== null) {
                $existing = LedgerTransaction::where(
                    'payment_attempt_id',
                    $paymentAttemptId
                )->first();

                if ($existing) {
                    throw new RuntimeException(
                        'Payment attempt has already been posted to the ledger.'
                    );
                }

                $paymentAttempt = PaymentAttempt::find($paymentAttemptId);

                if (!$paymentAttempt) {
                    throw new RuntimeException(
                        'Payment attempt not found.'
                    );
                }
            }

            /*
             * Verify every ledger account exists before creating
             * the transaction.
             */
            foreach ($entries as $entry) {
                $account = LedgerAccount::find(
                    $entry['ledger_account_id']
                );

                if (!$account) {
                    throw new RuntimeException(
                        'Ledger account not found.'
                    );
                }

                if ($account->currency !== $currency) {
                    throw new InvalidArgumentException(
                        'Ledger account currency does not match transaction currency.'
                    );
                }
            }

            $transaction = LedgerTransaction::create([
                'type' => $type,
                'amount' => $amount,
                'currency' => $currency,
                'direction' => $direction,
                'payment_attempt_id' => $paymentAttemptId,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'posted_at' => now(),
            ]);

            foreach ($entries as $entry) {
                LedgerEntry::create([
                    'ledger_transaction_id' => $transaction->id,
                    'ledger_account_id' => $entry['ledger_account_id'],
                    'type' => $entry['type'],
                    'amount' => $entry['amount'],
                    'currency' => $entry['currency'],
                ]);
            }

            return $transaction->fresh();
        });
    }

    private function validateTransaction(
        int $amount,
        string $currency,
        string $direction,
        array $entries
    ): void {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                'Ledger transaction amount must be greater than zero.'
            );
        }

        if (!in_array($direction, ['debit', 'credit'], true)) {
            throw new InvalidArgumentException(
                "Invalid ledger transaction direction [{$direction}]."
            );
        }

        if (count($entries) < 2) {
            throw new InvalidArgumentException(
                'A ledger transaction must contain at least two entries.'
            );
        }

        $debitTotal = 0;
        $creditTotal = 0;

        $hasDebit = false;
        $hasCredit = false;

        foreach ($entries as $entry) {
            if (
                !isset(
                    $entry['ledger_account_id'],
                    $entry['type'],
                    $entry['amount'],
                    $entry['currency']
                )
            ) {
                throw new InvalidArgumentException(
                    'Each ledger entry must contain account, type, amount and currency.'
                );
            }

            if (!in_array($entry['type'], ['debit', 'credit'], true)) {
                throw new InvalidArgumentException(
                    "Invalid ledger entry type [{$entry['type']}]."
                );
            }

            if ($entry['amount'] <= 0) {
                throw new InvalidArgumentException(
                    'Ledger entry amount must be greater than zero.'
                );
            }

            if ($entry['currency'] !== $currency) {
                throw new InvalidArgumentException(
                    'Ledger entry currency must match transaction currency.'
                );
            }

            if ($entry['type'] === 'debit') {
                $hasDebit = true;
                $debitTotal += $entry['amount'];
            }

            if ($entry['type'] === 'credit') {
                $hasCredit = true;
                $creditTotal += $entry['amount'];
            }
        }

        if (!$hasDebit) {
            throw new InvalidArgumentException(
                'Ledger transaction must contain at least one debit entry.'
            );
        }

        if (!$hasCredit) {
            throw new InvalidArgumentException(
                'Ledger transaction must contain at least one credit entry.'
            );
        }

        if ($debitTotal !== $creditTotal) {
            throw new InvalidArgumentException(
                'Ledger transaction is not balanced.'
            );
        }

        if ($debitTotal !== $amount) {
            throw new InvalidArgumentException(
                'Ledger entry total must match transaction amount.'
            );
        }
    }
}