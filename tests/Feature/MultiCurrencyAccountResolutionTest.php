<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Payout;
use App\Services\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('resolves the correct currency-specific account when a merchant has multiple currency accounts', function () {
    // 1. Setup Merchant
    $merchant = Merchant::factory()->create();

    // 2. Create Asset accounts for both currencies
    $pkrClearing = LedgerAccount::create([
        'name' => 'Clearing PKR',
        'type' => 'asset',
        'currency' => 'PKR',
        'status' => 'active',
    ]);

    $usdClearing = LedgerAccount::create([
        'name' => 'Clearing USD',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    // 3. Create BOTH PKR and USD Liability accounts for the same merchant
    $pkrPayable = LedgerAccount::create([
        'name' => 'Merchant Payable PKR',
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $usdPayable = LedgerAccount::create([
        'name' => 'Merchant Payable USD',
        'type' => 'liability',
        'currency' => 'USD',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $postingService = app(LedgerPostingService::class);

    // 4. Fund both accounts independently
    // Fund PKR with 10,000 PKR
    DB::transaction(function () use ($postingService, $pkrClearing, $pkrPayable) {
        $postingService->post(
            type: 'funding_pkr',
            amount: 10000,
            currency: 'PKR',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => $pkrClearing->id, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'],
                ['ledger_account_id' => $pkrPayable->id, 'type' => 'credit', 'amount' => 10000, 'currency' => 'PKR'],
            ],
            referenceType: 'funding',
            referenceId: (string) Str::uuid(),
            description: 'PKR funding'
        );
    });

    // Fund USD with 1,000 USD
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

    // 5. Trigger a USD Payout (e.g., 200 USD)
    $usdPayout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 200,
        'currency' => 'USD', // Explicitly USD
        'status' => 'completed',
    ]);

    $postingService->postFromPayout($usdPayout);

    // 6. Verify that resolution picked the USD account and left PKR completely untouched
    $pkrBalance = (int) $pkrPayable->entries()->where('type', 'credit')->sum('amount') 
                - (int) $pkrPayable->entries()->where('type', 'debit')->sum('amount');

    $usdBalance = (int) $usdPayable->entries()->where('type', 'credit')->sum('amount') 
                - (int) $usdPayable->entries()->where('type', 'debit')->sum('amount');

    // PKR should stay exactly at its funded 10,000
    expect($pkrBalance)->toBe(10000)
        // USD should decrease by 200: 1,000 - 200 = 800 USD
        ->and($usdBalance)->toBe(800);
});