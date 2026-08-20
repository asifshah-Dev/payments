<?php

namespace App\Services;

use App\Models\LedgerAccount;
use RuntimeException;

class LedgerAccountResolver
{
    public function resolve(string $currency): array
    {
        $accounts = LedgerAccount::query()
            ->where('currency', $currency)
            ->where('status', 'active')
            ->whereIn('type', ['asset', 'liability'])
            ->get();

        $debitAccounts = $accounts
            ->where('type', 'asset')
            ->values();

        $creditAccounts = $accounts
            ->where('type', 'liability')
            ->values();

        if ($debitAccounts->count() === 0) {
            throw new RuntimeException(
                "No active debit ledger account found for currency [{$currency}]."
            );
        }

        if ($debitAccounts->count() > 1) {
            throw new RuntimeException(
                "Multiple active debit ledger accounts found for currency [{$currency}]."
            );
        }

        if ($creditAccounts->count() === 0) {
            throw new RuntimeException(
                "No active credit ledger account found for currency [{$currency}]."
            );
        }

        if ($creditAccounts->count() > 1) {
            throw new RuntimeException(
                "Multiple active credit ledger accounts found for currency [{$currency}]."
            );
        }

        return [
            'debit' => $debitAccounts->first(),
            'credit' => $creditAccounts->first(),
        ];
    }
}