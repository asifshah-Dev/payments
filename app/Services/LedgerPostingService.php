<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LedgerPostingService
{
    /**
     * Automatically resolve context mapping and post from a payment attempt.
     */
    public function postFromPaymentAttempt(PaymentAttempt $paymentAttempt): LedgerTransaction
    {
        if ($paymentAttempt->status !== 'succeeded') {
            throw new RuntimeException('Cannot post ledger transaction for a non-successful payment attempt.');
        }

        // Automatically resolve matching accounts for this payment context
        $cashAccount = LedgerAccount::where('type', 'asset')
            ->where('currency', $paymentAttempt->currency)
            ->when(isset($paymentAttempt->merchant_id), fn($q) => $q->where('merchant_id', $paymentAttempt->merchant_id))
            ->first();

        $merchantAccount = LedgerAccount::where('type', 'liability')
            ->where('currency', $paymentAttempt->currency)
            ->when(isset($paymentAttempt->merchant_id), fn($q) => $q->where('merchant_id', $paymentAttempt->merchant_id))
            ->first();

        if (!$cashAccount || !$merchantAccount) {
            throw new RuntimeException('No account mapping found for this payment context.');
        }

        return $this->post(
            type: 'payment_capture',
            amount: $paymentAttempt->amount,
            currency: $paymentAttempt->currency,
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => $cashAccount->id,
                    'type' => 'debit',
                    'amount' => $paymentAttempt->amount,
                    'currency' => $paymentAttempt->currency,
                ],
                [
                    'ledger_account_id' => $merchantAccount->id,
                    'type' => 'credit',
                    'amount' => $paymentAttempt->amount,
                    'currency' => $paymentAttempt->currency,
                ],
            ],
            paymentAttemptId: $paymentAttempt->id,
            description: 'Automatic payment capture for attempt ' . $paymentAttempt->id
        );
    }

    /**
     * Automatically resolve context mapping and post from a payout.
     */
    public function postFromPayout(Payout $payout): LedgerTransaction
    {
        if ($payout->status !== 'completed') {
            throw new RuntimeException('Cannot post ledger transaction for a non-completed payout.');
        }

        // Automatically resolve matching accounts for this payout context
        $merchantAccount = LedgerAccount::where('type', 'liability')
            ->where('currency', $payout->currency)
            ->where('merchant_id', $payout->merchant_id)
            ->first();

        $cashAccount = LedgerAccount::where('type', 'asset')
            ->where('currency', $payout->currency)
            ->whereNull('merchant_id')
            ->first();

        if (!$merchantAccount || !$cashAccount) {
            throw new RuntimeException('No account mapping found for this payout context.');
        }

        return $this->post(
            type: 'merchant_payout',
            amount: $payout->amount,
            currency: $payout->currency,
            direction: 'debit',
            entries: [
                [
                    'ledger_account_id' => $merchantAccount->id,
                    'type' => 'debit',
                    'amount' => $payout->amount,
                    'currency' => $payout->currency,
                ],
                [
                    'ledger_account_id' => $cashAccount->id,
                    'type' => 'credit',
                    'amount' => $payout->amount,
                    'currency' => $payout->currency,
                ],
            ],
            referenceType: 'payout',
            referenceId: $payout->id,
            description: 'Automatic payout settlement for payout ' . $payout->id
        );
    }

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

        try {
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

                    // ... validation ...
                }

                // Add this check for generic references (like payouts)
                if ($referenceType !== null && $referenceId !== null) {
                    $existingRef = LedgerTransaction::where('reference_type', $referenceType)
                        ->where('reference_id', $referenceId)
                        ->first();

                    if ($existingRef) {
                        throw new RuntimeException(
                            'A ledger transaction for this reference has already been posted.'
                        );
                    }
                }

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

                // 1. Create transaction as unposted (posted_at = null) so entries can be added
                $transaction = LedgerTransaction::create([
                    'type' => $type,
                    'amount' => $amount,
                    'currency' => $currency,
                    'direction' => $direction,
                    'payment_attempt_id' => $paymentAttemptId,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'description' => $description,
                    'posted_at' => null,
                ]);

                // 2. Attach entries safely while posted_at is null
                foreach ($entries as $entry) {
                    LedgerEntry::create([
                        'ledger_transaction_id' => $transaction->id,
                        'ledger_account_id' => $entry['ledger_account_id'],
                        'type' => $entry['type'],
                        'amount' => $entry['amount'],
                        'currency' => $entry['currency'],
                    ]);
                }

                // 3. Mark the transaction as posted now that entries are attached
                $transaction->update(['posted_at' => now()]);

                return $transaction->fresh();
            });
        } catch (QueryException $e) {
            // Handle database-level unique constraint violation gracefully (Duplicate key error)
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'unique constraint') || str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new RuntimeException('Payment attempt has already been posted to the ledger.');
            }
            throw $e;
        }
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