<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Refund;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class LedgerPostingService
{
    public function postFromPaymentAttempt(PaymentAttempt $paymentAttempt): LedgerTransaction
    {
        if ($paymentAttempt->status !== 'succeeded') {
            throw new RuntimeException('Only succeeded payment attempts can be posted to the ledger.');
        }

        $feeAmount = (int) ($paymentAttempt->fee_amount ?? 0);
        if ($feeAmount < 0 || $feeAmount > $paymentAttempt->amount) {
            throw new InvalidArgumentException('Payment fee must be between zero and the payment amount.');
        }

        $existing = LedgerTransaction::query()
            ->where('payment_attempt_id', $paymentAttempt->id)
            ->where('type', 'payment')
            ->first();

        if ($existing) {
            throw new RuntimeException('This payment attempt has already been posted to the ledger.');
        }

        $merchantPayable = LedgerAccount::query()
            ->where('merchant_id', $paymentAttempt->paymentIntent->merchant_id)
            ->where('type', 'liability')
            ->where('currency', $paymentAttempt->currency)
            ->where('status', 'active')
            ->firstOrFail();
        $clearing = LedgerAccount::query()
            ->whereNull('merchant_id')
            ->where('type', 'asset')
            ->where('currency', $paymentAttempt->currency)
            ->where('status', 'active')
            ->firstOrFail();

        $entries = [[
            'ledger_account_id' => $clearing->id,
            'type' => 'debit',
            'amount' => $paymentAttempt->amount,
            'currency' => $paymentAttempt->currency,
        ], [
            'ledger_account_id' => $merchantPayable->id,
            'type' => 'credit',
            'amount' => $paymentAttempt->amount - $feeAmount,
            'currency' => $paymentAttempt->currency,
        ]];

        if ($feeAmount > 0) {
            $feeRevenue = LedgerAccount::query()
                ->whereNull('merchant_id')
                ->where('type', 'revenue')
                ->where('currency', $paymentAttempt->currency)
                ->where('status', 'active')
                ->firstOrFail();

            $entries[] = [
                'ledger_account_id' => $feeRevenue->id,
                'type' => 'credit',
                'amount' => $feeAmount,
                'currency' => $paymentAttempt->currency,
            ];
        }

        $transaction = $this->post(
            type: 'payment',
            amount: $paymentAttempt->amount,
            currency: $paymentAttempt->currency,
            direction: 'credit',
            entries: $entries,
            source: $paymentAttempt,
            description: 'Payment received',
        );

        $transaction->update(['payment_attempt_id' => $paymentAttempt->id]);

        return $transaction->fresh('entries');
    }

    public function postRefund(Refund $refund): LedgerTransaction
    {
        return DB::transaction(function () use ($refund) {
            $refund = Refund::query()
                ->with('paymentIntent')
                ->lockForUpdate()
                ->findOrFail($refund->id);

            if ($refund->status !== 'succeeded') {
                throw new RuntimeException('Only succeeded refunds can be posted to the ledger.');
            }

            $existing = LedgerTransaction::query()
                ->where('source_type', Refund::class)
                ->where('source_id', $refund->id)
                ->where('type', 'refund')
                ->first();

            if ($existing) {
                return $existing->load('entries');
            }

            $paymentAmount = (int) $refund->paymentIntent->amount;
            $refundedAmount = (int) $refund->paymentIntent->refunds()
                ->where('status', 'succeeded')
                ->whereKeyNot($refund->id)
                ->sum('amount');

            if ($refund->amount <= 0 || $refundedAmount + $refund->amount > $paymentAmount) {
                throw new InvalidArgumentException('Refund amount exceeds the refundable payment amount.');
            }

            $paymentAttempt = $refund->paymentIntent->attempts()
                ->where('status', 'succeeded')
                ->latest()
                ->first();
            $feeAmount = (int) ($paymentAttempt?->fee_amount ?? 0);
            $feeRefund = intdiv($refund->amount * $feeAmount, $paymentAmount);
            $payableRefund = $refund->amount - $feeRefund;

            $clearing = LedgerAccount::query()
                ->whereNull('merchant_id')
                ->where('type', 'asset')
                ->where('currency', $refund->currency)
                ->where('status', 'active')
                ->firstOrFail();
            $merchantPayable = LedgerAccount::query()
                ->where('merchant_id', $refund->merchant_id)
                ->where('type', 'liability')
                ->where('currency', $refund->currency)
                ->where('status', 'active')
                ->firstOrFail();

            $entries = [
                [
                    'ledger_account_id' => $merchantPayable->id,
                    'type' => 'debit',
                    'amount' => $payableRefund,
                    'currency' => $refund->currency,
                ],
            ];

            if ($feeRefund > 0) {
                $feeRevenue = LedgerAccount::query()
                    ->whereNull('merchant_id')
                    ->where('type', 'revenue')
                    ->where('currency', $refund->currency)
                    ->where('status', 'active')
                    ->firstOrFail();

                $entries[] = [
                    'ledger_account_id' => $feeRevenue->id,
                    'type' => 'debit',
                    'amount' => $feeRefund,
                    'currency' => $refund->currency,
                ];
            }

            $entries[] = [
                'ledger_account_id' => $clearing->id,
                'type' => 'credit',
                'amount' => $refund->amount,
                'currency' => $refund->currency,
            ];

            return $this->post(
                type: 'refund',
                amount: $refund->amount,
                currency: $refund->currency,
                direction: 'debit',
                entries: $entries,
                source: $refund,
                description: 'Refund posted',
            );
        });
    }

    /**
     * Post a new double-entry ledger transaction.
     *
     * @param string $type
     * @param int $amount
     * @param string $currency
     * @param string $direction
     * @param array $entries
     * @param Model|null $source Upstream business event causing this transaction
     * @param string|null $description
     * @return LedgerTransaction
     */
    public function post(
        string $type,
        int $amount,
        string $currency,
        string $direction,
        array $entries,
        ?Model $source = null,
        ?string $description = null
    ): LedgerTransaction {
        if (empty($entries)) {
            throw new InvalidArgumentException('A ledger transaction must contain at least one entry.');
        }

        return DB::transaction(function () use ($type, $amount, $currency, $direction, $entries, $source, $description) {
            // 1. Create the parent transaction record without 'posted_at' initially
            // so LedgerEntry protection allows adding entries.
            $transaction = LedgerTransaction::create([
                'type' => $type,
                'amount' => $amount,
                'currency' => $currency,
                'direction' => $direction,
                'description' => $description,
                'source_type' => $source ? get_class($source) : null,
                'source_id' => $source?->getKey(),
                'posted_at' => null, 
            ]);

            $totalDebits = 0;
            $totalCredits = 0;

            // 2. Process and create each ledger entry
            foreach ($entries as $entryData) {
                $account = LedgerAccount::findOrFail($entryData['ledger_account_id']);

                if ($account->currency !== $entryData['currency']) {
                    throw new InvalidArgumentException("Ledger account currency does not match transaction currency.");
                }

                if ($entryData['currency'] !== $currency) {
                    throw new InvalidArgumentException("All entry currencies must match the parent transaction currency.");
                }

                if ($entryData['type'] === 'debit') {
                    $totalDebits += $entryData['amount'];
                } else {
                    $totalCredits += $entryData['amount'];
                }

                LedgerEntry::create([
                    'ledger_transaction_id' => $transaction->id,
                    'ledger_account_id' => $account->id,
                    'type' => $entryData['type'],
                    'amount' => $entryData['amount'],
                    'currency' => $entryData['currency'],
                ]);
            }

            // 3. Enforce strict double-entry accounting balance
            if ($totalDebits !== $totalCredits) {
                throw new RuntimeException("Unbalanced ledger entry: Total debits ($totalDebits) must equal total credits ($totalCredits).");
            }

            // 4. Now that entries are safely attached, mark the transaction as posted
            $transaction->update(['posted_at' => now()]);

            return $transaction->load('entries');
        });
    }

    /**
     * Reverse an existing ledger transaction.
     */
    public function reverse(LedgerTransaction $transaction, ?string $description = null): LedgerTransaction
    {
        if ($transaction->entries()->count() === 0) {
            throw new RuntimeException('Cannot reverse a transaction with no entries.');
        }

        $existingReversal = LedgerTransaction::where('reference_type', 'reversal')
            ->where('reference_id', $transaction->id)
            ->exists();

        if ($existingReversal) {
            throw new RuntimeException('This ledger transaction has already been reversed.');
        }

        return DB::transaction(function () use ($transaction, $description) {
            $invertedEntries = [];

            foreach ($transaction->entries as $entry) {
                if ($entry->currency !== $transaction->currency) {
                    throw new InvalidArgumentException('Ledger account currency does not match transaction currency.');
                }

                $invertedEntries[] = [
                    'ledger_account_id' => $entry->ledger_account_id,
                    'type' => $entry->type === 'debit' ? 'credit' : 'debit',
                    'amount' => $entry->amount,
                    'currency' => $entry->currency,
                ];
            }

            $reversalDirection = $transaction->direction === 'debit' ? 'credit' : 'debit';

            $reversalTransaction = $this->post(
                type: $transaction->type . '_reversal',
                amount: $transaction->amount,
                currency: $transaction->currency,
                direction: $reversalDirection,
                entries: $invertedEntries,
                source: $transaction->source, 
                description: $description ?? "Reversal of transaction #{$transaction->id}"
            );

            // Link the reversal back to the original transaction
            $reversalTransaction->update([
                'reference_type' => 'reversal',
                'reference_id' => $transaction->id,
            ]);

            return $reversalTransaction;
        });
    }
}