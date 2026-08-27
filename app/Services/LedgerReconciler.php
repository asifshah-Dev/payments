<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class LedgerReconciler
{
    /**
     * Reconcile a source record (e.g., PaymentAttempt) with its associated ledger transactions.
     */
    public function reconcile(Model $source): array
    {
        $discrepancies = [];

        // Fetch related posted ledger transactions for this source
        $transactions = \App\Models\LedgerTransaction::where('source_type', get_class($source))
            ->where('source_id', $source->id)
            ->whereNotNull('posted_at')
            ->get();

        $totalLedgerAmount = $transactions->sum('amount');
        $sourceAmount = $source->amount ?? 0;

        if ($totalLedgerAmount !== $sourceAmount) {
            $discrepancies[] = [
                'type' => 'amount_mismatch',
                'source_id' => $source->id,
                'source_amount' => $sourceAmount,
                'ledger_amount' => $totalLedgerAmount,
                'message' => "Source amount ({$sourceAmount}) does not match total ledger transaction amount ({$totalLedgerAmount}).",
            ];
        }

        return $discrepancies;
    }
}