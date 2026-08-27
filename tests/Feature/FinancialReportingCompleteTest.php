<?php

use App\Models\LedgerTransaction;
use App\Models\Account;
use App\Services\FinancialReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (!Schema::hasTable('financial_reports_meta')) {
        Schema::create('financial_reports_meta', function (Blueprint $table) {
            $table->id();
            $table->string('currency');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->default(0);
            $table->timestamps();
        });
    }
});

it('calculates account balance report respecting debit and credit rules and separating currencies', function () {
    // USD Account Transactions
    LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);
    LedgerTransaction::factory()->create([
        'amount' => 2000,
        'currency' => 'USD',
        'direction' => 'credit',
        'posted_at' => now(),
    ]);

    // PKR Account Transactions
    LedgerTransaction::factory()->create([
        'amount' => 50000,
        'currency' => 'PKR',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reporter = new FinancialReporter();
    $balances = $reporter->getAccountBalances();

    expect($balances['USD'])->toBe(8000.00)
        ->and($balances['PKR'])->toBe(50000.00);
});

it('calculates period activity report with opening, debits, credits, and closing balances', function () {
    $reporter = new FinancialReporter();
    
    // Seed initial period activity
    LedgerTransaction::factory()->create(['amount' => 15000, 'currency' => 'USD', 'direction' => 'debit', 'posted_at' => '2026-08-10 10:00:00']);
    LedgerTransaction::factory()->create(['amount' => 3000, 'currency' => 'USD', 'direction' => 'credit', 'posted_at' => '2026-08-15 10:00:00']);

    $report = $reporter->getPeriodActivity('2026-08-01', '2026-08-31', 'USD', 1000.00);

    expect($report['opening_balance'])->toBe(1000.00)
        ->and($report['total_debits'])->toBe(150.00) // normalized or decimal representation (15000 cents = 150.00 or direct amount)
        ->and($report['total_credits'])->toBe(30.00)
        ->and($report['closing_balance'])->toBe(1120.00); // 1000 + 150 - 30
});

it('generates merchant statement showing payments, refunds, payouts, and net balance', function () {
    $merchantId = 'merchant-123';

    // Simulate merchant ledger entries
    LedgerTransaction::factory()->create(['source_id' => $merchantId, 'amount' => 10000, 'currency' => 'USD', 'direction' => 'debit', 'type' => 'payment', 'posted_at' => now()]);
    LedgerTransaction::factory()->create(['source_id' => $merchantId, 'amount' => 2000, 'currency' => 'USD', 'direction' => 'credit', 'type' => 'refund', 'posted_at' => now()]);
    LedgerTransaction::factory()->create(['source_id' => $merchantId, 'amount' => 5000, 'currency' => 'USD', 'direction' => 'credit', 'type' => 'payout', 'posted_at' => now()]);

    $reporter = new FinancialReporter();
    $statement = $reporter->getMerchantStatement($merchantId, 'USD');

    expect($statement['payments'])->toBe(100.00)
        ->and($statement['refunds'])->toBe(20.00)
        ->and($statement['payouts'])->toBe(50.00)
        ->and($statement['net_balance'])->toBe(30.00);
});

it('tracks platform revenue report across payment fees and chargeback fees', function () {
    LedgerTransaction::factory()->create(['amount' => 300, 'currency' => 'USD', 'direction' => 'credit', 'type' => 'fee', 'posted_at' => now()]);
    LedgerTransaction::factory()->create(['amount' => 150, 'currency' => 'USD', 'direction' => 'credit', 'type' => 'chargeback_fee', 'posted_at' => now()]);

    $reporter = new FinancialReporter();
    $revenue = $reporter->getPlatformRevenue('USD');

    expect($revenue['total_fees'])->toBe(4.50)
        ->and($revenue['net_revenue'])->toBe(4.50);
});

it('satisfies trial balance report where total debits equal total credits', function () {
    LedgerTransaction::factory()->create(['amount' => 10000, 'currency' => 'USD', 'direction' => 'debit', 'posted_at' => now()]);
    LedgerTransaction::factory()->create(['amount' => 10000, 'currency' => 'USD', 'direction' => 'credit', 'posted_at' => now()]);

    $reporter = new FinancialReporter();
    $trialBalance = $reporter->getTrialBalance('USD');

    expect($trialBalance['total_debits'])->toBe($trialBalance['total_credits'])
        ->and($trialBalance['is_balanced'])->toBeTrue();
});

it('reconciles multi-currency reports independently without mixing currencies', function () {
    LedgerTransaction::factory()->create(['amount' => 5000, 'currency' => 'USD', 'direction' => 'debit', 'posted_at' => now()]);
    LedgerTransaction::factory()->create(['amount' => 50000, 'currency' => 'PKR', 'direction' => 'debit', 'posted_at' => now()]);

    $reporter = new FinancialReporter();
    $multiReport = $reporter->getMultiCurrencyReport(['USD', 'PKR']);

    expect($multiReport['USD']['total_volume'])->toBe(50.00)
        ->and($multiReport['PKR']['total_volume'])->toBe(500.00);
});

it('produces a complete financial report snapshot for a closed accounting period', function () {
    $reporter = new FinancialReporter();
    $snapshot = $reporter->getPeriodClosingReport('2026-08-01', '2026-08-31', 'USD');

    expect($snapshot)->toHaveKeys(['opening_balance', 'activity', 'closing_balance', 'status'])
        ->and($snapshot['status'])->toBe('reproduced');
});