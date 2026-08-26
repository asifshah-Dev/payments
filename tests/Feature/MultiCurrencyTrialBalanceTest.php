<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Payout;
use App\Services\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('maintains balanced trial balances independently for each currency', function () {
    $merchant = Merchant::factory()->create();

    // 1. Setup accounts for PKR and USD
    $pkrClearing = LedgerAccount::create([
        'name' => 'Clearing PKR',
        'type' => 'asset',
        'currency' => 'PKR',
        'status' => 'active',
    ]);

    $pkrPayable = LedgerAccount::create([
        'name' => 'Payable PKR',
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $usdClearing = LedgerAccount::create([
        'name' => 'Clearing USD',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $usdPayable = LedgerAccount::create([
        'name' => 'Payable USD',
        'type' => 'liability',
        'currency' => 'USD',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $postingService = app(LedgerPostingService::class);

    // 2. Post multi-currency transactions
    // Fund PKR
    DB::transaction(function () use ($postingService, $pkrClearing, $pkrPayable) {
        $postingService->post(
            type: 'funding_pkr',
            amount: 20000,
            currency: 'PKR',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => $pkrClearing->id, 'type' => 'debit', 'amount' => 20000, 'currency' => 'PKR'],
                ['ledger_account_id' => $pkrPayable->id, 'type' => 'credit', 'amount' => 20000, 'currency' => 'PKR'],
            ],
            referenceType: 'funding',
            referenceId: (string) Str::uuid(),
            description: 'PKR funding'
        );
    });

    // Fund USD
    DB::transaction(function () use ($postingService, $usdClearing, $usdPayable) {
        $postingService->post(
            type: 'funding_usd',
            amount: 1000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => $usdClearing->id, 'type' => 'debit', 'amount' => 1000, 'currency' => 'USD'],
                ['ledger_account_id' => $usdPayable->id, 'type' => 'credit', 'amount' => 1000, 'currency' => 'USD'],
            ],
            referenceType: 'funding',
            referenceId: (string) Str::uuid(),
            description: 'USD funding'
        );
    });

    // Process PKR Payout
    $pkrPayout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);
    $postingService->postFromPayout($pkrPayout);

    // 3. Calculate Trial Balance Per Currency
    // PKR Trial Balance
    $pkrDebits = DB::table('ledger_entries')->where('currency', 'PKR')->where('type', 'debit')->sum('amount');
    $pkrCredits = DB::table('ledger_entries')->where('currency', 'PKR')->where('type', 'credit')->sum('amount');

    // USD Trial Balance
    $usdDebits = DB::table('ledger_entries')->where('currency', 'USD')->where('type', 'debit')->sum('amount');
    $usdCredits = DB::table('ledger_entries')->where('currency', 'USD')->where('type', 'credit')->sum('amount');

    // 4. Assertions: Each currency must balance completely on its own
    expect((int) $pkrDebits)->toBe((int) $pkrCredits)
        ->and((int) $usdDebits)->toBe((int) $usdCredits)
        // PKR total volume check: Initial 20k + 5k payout = 25k total debits/credits touched
        ->and((int) $pkrDebits)->toBe(25000)
        // USD total volume check: 1k debits/credits
        ->and((int) $usdDebits)->toBe(1000);
});