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

        $feeAmount = $paymentAttempt->fee_amount ?? 0;

        if ($feeAmount < 0) {
            throw new InvalidArgumentException('Fee amount cannot be negative.');
        }

        if ($feeAmount > $paymentAttempt->amount) {
            throw new InvalidArgumentException('Fee amount cannot exceed the payment amount.');
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

        $entries = [
            [
                'ledger_account_id' => $cashAccount->id,
                'type' => 'debit',
                'amount' => $paymentAttempt->amount,
                'currency' => $paymentAttempt->currency,
            ],
            [
                'ledger_account_id' => $merchantAccount->id,
                'type' => 'credit',
                'amount' => $paymentAttempt->amount - $feeAmount,
                'currency' => $paymentAttempt->currency,
            ],
        ];

        // If a fee is present, add the platform fee revenue credit entry
        if ($feeAmount > 0) {
            $feeAccount = LedgerAccount::where('type', 'revenue')
                ->where('currency', $paymentAttempt->currency)
                ->whereNull('merchant_id')
                ->first();

            if (!$feeAccount) {
                throw new RuntimeException('Platform fee revenue account could not be found.');
            }

            $entries[] = [
                'ledger_account_id' => $feeAccount->id,
                'type' => 'credit',
                'amount' => $feeAmount,
                'currency' => $paymentAttempt->currency,
            ];
        }

        return $this->post(
            type: 'payment_capture',
            amount: $paymentAttempt->amount,
            currency: $paymentAttempt->currency,
            direction: 'credit',
            entries: $entries,
            paymentAttemptId: $paymentAttempt->id,
            description: 'Automatic payment capture for attempt ' . $paymentAttempt->id
        );
    }

    /**
     * Post a refund transaction for a given payment attempt, ensuring cumulative refunds do not exceed the payment amount.
     */
    public function postRefundFromPaymentAttempt(PaymentAttempt $paymentAttempt, int $refundAmount): LedgerTransaction
    {
        if ($refundAmount <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }

        // Calculate total already refunded for this payment attempt
        $alreadyRefunded = LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)
            ->where('type', 'payment_refund')
            ->sum('amount');

        $remainingRefundable = $paymentAttempt->amount - $alreadyRefunded;

        if ($refundAmount > $remainingRefundable) {
            throw new InvalidArgumentException('Refund amount exceeds the remaining refundable balance of ' . $remainingRefundable);
        }

        $feeAmount = $paymentAttempt->fee_amount ?? 0;

        // Calculate pro-rata fee reversal based on the original payment amount ratio
        $feeRefundAmount = 0;
        if ($feeAmount > 0 && $paymentAttempt->amount > 0) {
            // If this refund exhausts the exact remaining balance, sweep any remaining fee dust to prevent rounding discrepancies
            if ($refundAmount === $remainingRefundable) {
                $alreadyRefundedFee = LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)
                    ->where('type', 'payment_refund')
                    ->with('entries')
                    ->get()
                    ->flatMap->entries
                    ->where('ledger_account_id', LedgerAccount::where('type', 'revenue')->whereNull('merchant_id')->first()?->id)
                    ->where('type', 'debit')
                    ->sum('amount');

                $feeRefundAmount = $feeAmount - $alreadyRefundedFee;
            } else {
                $feeRefundAmount = (int) round(($refundAmount / $paymentAttempt->amount) * $feeAmount);
            }
        }

        $netRefundAmount = $refundAmount - $feeRefundAmount;

        // Resolve Accounts
        $cashAccount = LedgerAccount::where('type', 'asset')
            ->where('currency', $paymentAttempt->currency)
            ->whereNull('merchant_id')
            ->first();

        $merchantAccount = LedgerAccount::where('type', 'liability')
            ->where('currency', $paymentAttempt->currency)
            ->when(isset($paymentAttempt->merchant_id), fn($q) => $q->where('merchant_id', $paymentAttempt->merchant_id))
            ->first();

        if (!$cashAccount || !$merchantAccount) {
            throw new RuntimeException('No account mapping found for this refund context.');
        }

        $entries = [
            [
                'ledger_account_id' => $merchantAccount->id,
                'type' => 'debit',
                'amount' => $netRefundAmount,
                'currency' => $paymentAttempt->currency,
            ],
            [
                'ledger_account_id' => $cashAccount->id,
                'type' => 'credit',
                'amount' => $refundAmount,
                'currency' => $paymentAttempt->currency,
            ],
        ];

        if ($feeRefundAmount > 0) {
            $feeAccount = LedgerAccount::where('type', 'revenue')
                ->where('currency', $paymentAttempt->currency)
                ->whereNull('merchant_id')
                ->first();

            if (!$feeAccount) {
                throw new RuntimeException('Platform fee revenue account could not be found.');
            }

            $entries[] = [
                'ledger_account_id' => $feeAccount->id,
                'type' => 'debit',
                'amount' => $feeRefundAmount,
                'currency' => $paymentAttempt->currency,
            ];
        }

        return $this->post(
            type: 'payment_refund',
            amount: $refundAmount,
            currency: $paymentAttempt->currency,
            direction: 'debit',
            entries: $entries,
            paymentAttemptId: $paymentAttempt->id,
            description: 'Refund for payment attempt ' . $paymentAttempt->id
        );
    }

    /**
     * Automatically resolve context mapping and post from a payout.
     */
    public function postFromPayout(Payout $payout): LedgerTransaction
    {
        return DB::transaction(function () use ($payout) {
            if ($payout->status !== 'completed') {
                throw new RuntimeException('Cannot post ledger transaction for a non-completed payout.');
            }

            // Lock the merchant liability account row for update to prevent race conditions
            $merchantAccount = LedgerAccount::where('type', 'liability')
                ->where('currency', $payout->currency)
                ->where('merchant_id', $payout->merchant_id)
                ->lockForUpdate()
                ->first();

            $cashAccount = LedgerAccount::where('type', 'asset')
                ->where('currency', $payout->currency)
                ->whereNull('merchant_id')
                ->first();

            if (!$merchantAccount || !$cashAccount) {
                throw new RuntimeException('No account mapping found for this payout context.');
            }

            // Calculate available payable balance under lock protection
            $currentBalance = (int) $merchantAccount->entries()->where('type', 'credit')->sum('amount')
                - (int) $merchantAccount->entries()->where('type', 'debit')->sum('amount');

            if ($payout->amount > $currentBalance) {
                throw new RuntimeException('Payout amount exceeds available merchant payable balance.');
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
        });
    }

    public function reverse(LedgerTransaction $transaction, ?string $description = null): LedgerTransaction
    {
        return DB::transaction(function () use ($transaction, $description) {
            // 1. Enforce single-reversal guard
            $existingReversal = LedgerTransaction::where('reference_type', 'reversal')
                ->where('reference_id', (string) $transaction->id)
                ->first();

            if ($existingReversal) {
                throw new RuntimeException('This ledger transaction has already been reversed.');
            }

            // 2. Load the original entries
            $transaction->loadMissing('entries');

            if ($transaction->entries->isEmpty()) {
                throw new RuntimeException('Cannot reverse a transaction with no entries.');
            }

            // 3. Mirror every entry (swap debit <-> credit) and calculate integer debit total
            $mirroredEntries = [];
            $debitTotal = 0;

            foreach ($transaction->entries as $entry) {
                $newType = $entry->type === 'debit' ? 'credit' : 'debit';
                $amount = (int) $entry->amount;

                $mirroredEntries[] = [
                    'ledger_account_id' => $entry->ledger_account_id,
                    'type' => $newType,
                    'amount' => $amount,
                    'currency' => $entry->currency,
                ];

                if ($newType === 'debit') {
                    $debitTotal += $amount;
                }
            }

            // 4. Determine the opposite top-level direction
            $reversedDirection = $transaction->direction === 'debit' ? 'credit' : 'debit';

            // 5. Post the reversal using the calculated integer amount
            return $this->post(
                type: $transaction->type . '_reversal',
                amount: (int) $debitTotal, 
                currency: $transaction->currency,
                direction: $reversedDirection,
                entries: $mirroredEntries,
                referenceType: 'reversal',
                referenceId: (string) $transaction->id,
                description: $description ?? 'Reversal of transaction ' . $transaction->id
            );
        });
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
                if ($paymentAttemptId !== null && in_array($type, ['payment_capture', 'payment_chargeback'])) {
                    $existing = LedgerTransaction::where(
                        'payment_attempt_id',
                        $paymentAttemptId
                    )
                        ->where('type', $type)
                        ->first();

                    if ($existing) {
                        $label = $type === 'payment_capture' ? 'Payment attempt has already been posted to the ledger.' : 'Payment attempt has already been charged back.';
                        throw new RuntimeException($label);
                    }
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

    /**
     * Post a chargeback transaction for a given payment attempt.
     */
    public function postChargebackFromPaymentAttempt(PaymentAttempt $paymentAttempt, int $chargebackAmount): LedgerTransaction
    {
        if ($chargebackAmount <= 0) {
            throw new InvalidArgumentException('Chargeback amount must be greater than zero.');
        }

        if ($chargebackAmount > $paymentAttempt->amount) {
            throw new InvalidArgumentException('Chargeback amount cannot exceed the original payment amount.');
        }

        // Resolve Accounts
        $cashAccount = LedgerAccount::where('type', 'asset')
            ->where('currency', $paymentAttempt->currency)
            ->whereNull('merchant_id')
            ->first();

        $merchantAccount = LedgerAccount::where('type', 'liability')
            ->where('currency', $paymentAttempt->currency)
            ->when(isset($paymentAttempt->merchant_id), fn($q) => $q->where('merchant_id', $paymentAttempt->merchant_id))
            ->first();

        if (!$cashAccount || !$merchantAccount) {
            throw new RuntimeException('No account mapping found for this chargeback context.');
        }

        $entries = [
            [
                'ledger_account_id' => $merchantAccount->id,
                'type' => 'debit',
                'amount' => $chargebackAmount,
                'currency' => $paymentAttempt->currency,
            ],
            [
                'ledger_account_id' => $cashAccount->id,
                'type' => 'credit',
                'amount' => $chargebackAmount,
                'currency' => $paymentAttempt->currency,
            ],
        ];

        return $this->post(
            type: 'payment_chargeback',
            amount: $chargebackAmount,
            currency: $paymentAttempt->currency,
            direction: 'debit',
            entries: $entries,
            paymentAttemptId: $paymentAttempt->id,
            description: 'Chargeback for payment attempt ' . $paymentAttempt->id
        );
    }

    /**
     * Post a chargeback before payout, clawing funds back from the unpaid merchant balance and reversing fees.
     */
    public function postPrePayoutChargebackFromPaymentAttempt(PaymentAttempt $paymentAttempt, int $chargebackAmount): LedgerTransaction
    {
        if ($chargebackAmount <= 0) {
            throw new InvalidArgumentException('Chargeback amount must be greater than zero.');
        }

        $cashAccount = LedgerAccount::where('type', 'asset')
            ->where('currency', $paymentAttempt->currency)
            ->whereNull('merchant_id')
            ->first();

        $merchantAccount = LedgerAccount::where('type', 'liability')
            ->where('currency', $paymentAttempt->currency)
            ->when(isset($paymentAttempt->merchant_id), fn($q) => $q->where('merchant_id', $paymentAttempt->merchant_id))
            ->first();

        $revenueAccount = LedgerAccount::where('type', 'revenue')
            ->where('currency', $paymentAttempt->currency)
            ->whereNull('merchant_id')
            ->first();

        if (!$cashAccount || !$merchantAccount || !$revenueAccount) {
            throw new RuntimeException('Account mapping missing for pre-payout chargeback.');
        }

        $feeAmount = (int) ($paymentAttempt->fee_amount ?? 0);
        $netMerchantShare = $chargebackAmount - $feeAmount;

        $entries = [
            [
                'ledger_account_id' => $merchantAccount->id,
                'type' => 'debit',
                'amount' => $netMerchantShare,
                'currency' => $paymentAttempt->currency,
            ],
            [
                'ledger_account_id' => $revenueAccount->id,
                'type' => 'debit',
                'amount' => $feeAmount,
                'currency' => $paymentAttempt->currency,
            ],
            [
                'ledger_account_id' => $cashAccount->id,
                'type' => 'credit',
                'amount' => $chargebackAmount,
                'currency' => $paymentAttempt->currency,
            ],
        ];

        return $this->post(
            type: 'pre_payout_chargeback',
            amount: $chargebackAmount,
            currency: $paymentAttempt->currency,
            direction: 'debit',
            entries: $entries,
            paymentAttemptId: $paymentAttempt->id,
            description: 'Pre-payout chargeback for payment attempt ' . $paymentAttempt->id
        );
    }

    /**
     * Post a chargeback reversal (merchant wins dispute) for a given payment attempt.
     */
    public function postChargebackReversalFromPaymentAttempt(PaymentAttempt $paymentAttempt, int $reversalAmount): LedgerTransaction
    {
        if ($reversalAmount <= 0) {
            throw new InvalidArgumentException('Reversal amount must be greater than zero.');
        }

        // Resolve Accounts
        $cashAccount = LedgerAccount::where('type', 'asset')
            ->where('currency', $paymentAttempt->currency)
            ->whereNull('merchant_id')
            ->first();

        $merchantAccount = LedgerAccount::where('type', 'liability')
            ->where('currency', $paymentAttempt->currency)
            ->when(isset($paymentAttempt->merchant_id), fn($q) => $q->where('merchant_id', $paymentAttempt->merchant_id))
            ->first();

        if (!$cashAccount || !$merchantAccount) {
            throw new RuntimeException('No account mapping found for chargeback reversal context.');
        }

        $entries = [
            [
                'ledger_account_id' => $cashAccount->id,
                'type' => 'debit',
                'amount' => $reversalAmount,
                'currency' => $paymentAttempt->currency,
            ],
            [
                'ledger_account_id' => $merchantAccount->id,
                'type' => 'credit',
                'amount' => $reversalAmount,
                'currency' => $paymentAttempt->currency,
            ],
        ];

        return $this->post(
            type: 'chargeback_reversal',
            amount: $reversalAmount,
            currency: $paymentAttempt->currency,
            direction: 'debit',
            entries: $entries,
            paymentAttemptId: $paymentAttempt->id,
            description: 'Chargeback reversal (won) for payment attempt ' . $paymentAttempt->id
        );
    }

    /**
     * Post a chargeback/dispute fee for a given payment attempt.
     */
    public function postChargebackFeeFromPaymentAttempt(PaymentAttempt $paymentAttempt, int $feeAmount): LedgerTransaction
    {
        if ($feeAmount <= 0) {
            throw new InvalidArgumentException('Chargeback fee amount must be greater than zero.');
        }

        // Resolve Accounts
        $merchantAccount = LedgerAccount::where('type', 'liability')
            ->where('currency', $paymentAttempt->currency)
            ->when(isset($paymentAttempt->merchant_id), fn($q) => $q->where('merchant_id', $paymentAttempt->merchant_id))
            ->first();

        $revenueAccount = LedgerAccount::where('type', 'revenue')
            ->where('currency', $paymentAttempt->currency)
            ->whereNull('merchant_id')
            ->first();

        if (!$merchantAccount || !$revenueAccount) {
            throw new RuntimeException('Account mapping missing for chargeback fee context.');
        }

        $entries = [
            [
                'ledger_account_id' => $merchantAccount->id,
                'type' => 'debit',
                'amount' => $feeAmount,
                'currency' => $paymentAttempt->currency,
            ],
            [
                'ledger_account_id' => $revenueAccount->id,
                'type' => 'credit',
                'amount' => $feeAmount,
                'currency' => $paymentAttempt->currency,
            ],
        ];

        return $this->post(
            type: 'chargeback_fee',
            amount: $feeAmount,
            currency: $paymentAttempt->currency,
            direction: 'debit',
            entries: $entries,
            paymentAttemptId: $paymentAttempt->id,
            description: 'Chargeback fee for payment attempt ' . $paymentAttempt->id
        );
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
                $debitTotal += (int) $entry['amount'];
            }

            if ($entry['type'] === 'credit') {
                $hasCredit = true;
                $creditTotal += (int) $entry['amount'];
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

        if ((int) $debitTotal !== (int) $creditTotal) {
            throw new InvalidArgumentException(
                'Ledger transaction is not balanced.'
            );
        }

        if ((int) $debitTotal !== (int) $amount) {
            throw new InvalidArgumentException(
                'Ledger entry total must match transaction amount.'
            );
        }
    }
}