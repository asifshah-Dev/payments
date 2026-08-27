<?php

namespace App\Services;

use App\Models\LedgerTransaction;
use Illuminate\Support\Collection;

class LedgerBatchReconciler
{
    /**
     * Perform a full batch reconciliation returning all summary metrics.
     */
    public function reconcileBatch(iterable $payments, iterable $transactions): array
    {
        $payments = collect($payments);
        $transactions = collect($transactions);

        $matched = collect();
        $missing = collect();
        $unexpected = collect();
        $mismatches = collect();
        $currencyMismatches = collect();
        $duplicates = collect();
        $totals = [];

        $transactionsBySource = $transactions->groupBy(fn ($tx) => $tx->source_type . ':' . $tx->source_id);

        // Track seen source IDs to detect duplicate source records if any
        $seenSources = [];

        foreach ($payments as $payment) {
            $key = get_class($payment) . ':' . $payment->id;
            $currency = $payment->currency ?? 'USD';

            $totals[$currency] = $totals[$currency] ?? ['source' => 0, 'ledger' => 0];
            $totals[$currency]['source'] += $payment->amount ?? 0;

            if (isset($seenSources[$key])) {
                // Duplicate source record handling if needed
            }
            $seenSources[$key] = true;

            if ($transactionsBySource->has($key)) {
                $relatedTransactions = $transactionsBySource->get($key);

                if ($relatedTransactions->count() > 1) {
                    $duplicates->push([
                        'payment_id' => $payment->id,
                        'transactions' => $relatedTransactions,
                    ]);
                }

                $ledgerAmount = $relatedTransactions->sum('amount');
                $sourceAmount = $payment->amount ?? 0;
                $ledgerCurrency = $relatedTransactions->first()->currency ?? $currency;

                if ($ledgerCurrency !== $currency) {
                    $currencyMismatches->push([
                        'payment' => $payment,
                        'source_currency' => $currency,
                        'ledger_currency' => $ledgerCurrency,
                    ]);
                } elseif ($ledgerAmount !== $sourceAmount) {
                    $mismatches->push([
                        'payment' => $payment,
                        'source_amount' => $sourceAmount,
                        'ledger_amount' => $ledgerAmount,
                    ]);
                } else {
                    $matched->push($payment);
                }

                foreach ($relatedTransactions as $tx) {
                    $totals[$currency]['ledger'] += $tx->amount ?? 0;
                }
            } else {
                $missing->push($payment);
            }
        }

        // Check transactions for unexpected entries
        $validPaymentKeys = $payments->map(fn ($p) => get_class($p) . ':' . $p->id)->toArray();

        foreach ($transactions as $transaction) {
            $key = $transaction->source_type . ':' . $transaction->source_id;

            if (!in_array($key, $validPaymentKeys)) {
                $unexpected->push($transaction);
                $curr = $transaction->currency ?? 'USD';
                $totals[$curr] = $totals[$curr] ?? ['source' => 0, 'ledger' => 0];
                $totals[$curr]['ledger'] += $transaction->amount ?? 0;
            }
        }

        $hasAnomalies = $missing->isNotEmpty() 
            || $unexpected->isNotEmpty() 
            || $mismatches->isNotEmpty() 
            || $currencyMismatches->isNotEmpty() 
            || $duplicates->isNotEmpty();

        return [
            'matched' => $matched,
            'missing' => $missing,
            'unexpected' => $unexpected,
            'mismatches' => $mismatches,
            'currency_mismatches' => $currencyMismatches,
            'duplicates' => $duplicates,
            'totals' => $totals,
            'status' => $hasAnomalies ? 'discrepancy' : 'reconciled',
        ];
    }

    public function findMissing(iterable $payments): Collection
    {
        return $this->reconcileBatch($payments, LedgerTransaction::whereNotNull('posted_at')->get())['missing'];
    }

    public function findUnexpected(iterable $transactions, iterable $payments): Collection
    {
        return $this->reconcileBatch($payments, $transactions)['unexpected'];
    }
}