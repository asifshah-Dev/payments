<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentConsistencyChecker
{
    protected string $paymentsTable;
    protected string $ledgerTable;

    public function __construct(string $paymentsTable = 'payments', string $ledgerTable = 'ledger_transactions')
    {
        $this->paymentsTable = $paymentsTable;
        $this->ledgerTable = $ledgerTable;
    }

    public function findInconsistentPayments(): Collection
    {
        $ledgerReferenceIds = DB::table($this->ledgerTable)
            ->whereNotNull('reference_id')
            ->pluck('reference_id')
            ->toArray();

        return DB::table($this->paymentsTable)
            ->where('status', 'succeeded')
            ->whereNotIn('id', $ledgerReferenceIds)
            ->get();
    }
}