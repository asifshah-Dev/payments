<?php

namespace App\Services;

use App\Models\LedgerTransaction;

class FinancialReporter
{
    public function getAccountBalances(): array
    {
        $transactions = LedgerTransaction::whereNotNull('posted_at')->get();
        $balances = [];

        foreach ($transactions as $tx) {
            $currency = $tx->currency ?? 'USD';
            $balances[$currency] = $balances[$currency] ?? 0.0;

            $amount = (float) $tx->amount;

            if ($tx->direction === 'debit') {
                $balances[$currency] += $amount;
            } else {
                $balances[$currency] -= $amount;
            }
        }

        foreach ($balances as $curr => $val) {
            $balances[$curr] = (float) $val;
        }

        return $balances;
    }

    public function getPeriodActivity(string $startDate, string $endDate, string $currency, float $openingBalance): array
    {
        $transactions = LedgerTransaction::where('currency', $currency)
            ->whereBetween('posted_at', [$startDate, $endDate])
            ->whereNotNull('posted_at')
            ->get();

        $debits = (float) ($transactions->where('direction', 'debit')->sum('amount') / 100);
        $credits = (float) ($transactions->where('direction', 'credit')->sum('amount') / 100);

        return [
            'opening_balance' => (float) $openingBalance,
            'total_debits' => (float) $debits,
            'total_credits' => (float) $credits,
            'closing_balance' => (float) ($openingBalance + $debits - $credits),
        ];
    }

    public function getMerchantStatement(string $merchantId, string $currency): array
    {
        $transactions = LedgerTransaction::where('source_id', $merchantId)
            ->where('currency', $currency)
            ->whereNotNull('posted_at')
            ->get();

        $payments = (float) ($transactions->where('type', 'payment')->sum('amount') / 100);
        $refunds = (float) ($transactions->where('type', 'refund')->sum('amount') / 100);
        $payouts = (float) ($transactions->where('type', 'payout')->sum('amount') / 100);

        return [
            'payments' => (float) $payments,
            'refunds' => (float) $refunds,
            'payouts' => (float) $payouts,
            'net_balance' => (float) ($payments - $refunds - $payouts),
        ];
    }

    public function getPlatformRevenue(string $currency): array
    {
        $fees = (float) (LedgerTransaction::where('currency', $currency)
            ->whereIn('type', ['fee', 'chargeback_fee'])
            ->whereNotNull('posted_at')
            ->sum('amount') / 100);

        return [
            'total_fees' => (float) $fees,
            'net_revenue' => (float) $fees,
        ];
    }

    public function getTrialBalance(string $currency): array
    {
        $transactions = LedgerTransaction::where('currency', $currency)
            ->whereNotNull('posted_at')
            ->get();

        $debits = (int) $transactions->where('direction', 'debit')->sum('amount');
        $credits = (int) $transactions->where('direction', 'credit')->sum('amount');

        return [
            'total_debits' => $debits,
            'total_credits' => $credits,
            'is_balanced' => $debits === $credits,
        ];
    }

    public function getMultiCurrencyReport(array $currencies): array
    {
        $report = [];
        foreach ($currencies as $currency) {
            $volume = (float) (LedgerTransaction::where('currency', $currency)
                ->whereNotNull('posted_at')
                ->sum('amount') / 100);

            $report[$currency] = ['total_volume' => (float) $volume];
        }
        return $report;
    }

    public function getPeriodClosingReport(string $startDate, string $endDate, string $currency): array
    {
        $activity = $this->getPeriodActivity($startDate, $endDate, $currency, 0.00);

        return [
            'opening_balance' => (float) $activity['opening_balance'],
            'activity' => $activity,
            'closing_balance' => (float) $activity['closing_balance'],
            'status' => 'reproduced',
        ];
    }
}