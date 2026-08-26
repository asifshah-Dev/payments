<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Payout;
use App\Services\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('isolates balances independently across different currencies for the same merchant', function () {
    // 1. Setup Merchant
    $merchant = Merchant::factory()->create();

    // 2. Create Asset (Clearing) accounts for both currencies
    $pkrClearing = LedgerAccount::create([
        'name' => 'Platform Clearing PKR',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    $usdClearing = LedgerAccount::create([
        'name' => 'Platform Clearing USD',
        'type' => 'asset',
        'currency' => 'USD',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    // 3. Create Liability (Merchant Payable) accounts for both PKR and USD
    $pkrPayable = LedgerAccount::create([
        'name' => 'Merchant Payable PKR - ' . $merchant->id,
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $usdPayable = LedgerAccount::create([
        'name' => 'Merchant Payable USD - ' . $merchant->id,
        'type' => 'liability',
        'currency' => 'USD',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $postingService = app(LedgerPostingService::class);

    // 4. Fund both currency balances independently using unique 36-char UUID reference IDs
    DB::transaction(function () use ($postingService, $pkrPayable, $pkrClearing) {
        $postingService->post(
            type: 'manual_funding_pkr',
            amount: 10000,
            currency: 'PKR',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => $pkrClearing->id, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'],
                ['ledger_account_id' => $pkrPayable->id, 'type' => 'credit', 'amount' => 10000, 'currency' => 'PKR'],
            ],
            referenceType: 'funding',
            referenceId: (string) Str::uuid(),
            description: 'Initial PKR funding'
        );
    });

    DB::transaction(function () use ($postingService, $usdPayable, $usdClearing) {
        $postingService->post(
            type: 'manual_funding_usd',
            amount: 500,
            currency: 'USD',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => $usdClearing->id, 'type' => 'debit', 'amount' => 500, 'currency' => 'USD'],
                ['ledger_account_id' => $usdPayable->id, 'type' => 'credit', 'amount' => 500, 'currency' => 'USD'],
            ],
            referenceType: 'funding',
            referenceId: (string) Str::uuid(),
            description: 'Initial USD funding'
        );
    });

    // 5. Perform a Payout in PKR (e.g., 4,000 PKR)
    $pkrPayout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 4000,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);

    $postingService->postFromPayout($pkrPayout);

    // 6. Verify Balances
    // PKR Balance should be: 10,000 - 4,000 = 6,000 PKR
    $pkrBalance = (int) $pkrPayable->entries()->where('type', 'credit')->sum('amount') 
                - (int) $pkrPayable->entries()->where('type', 'debit')->sum('amount');

    // USD Balance should remain completely untouched at: 500 USD
    $usdBalance = (int) $usdPayable->entries()->where('type', 'credit')->sum('amount') 
                - (int) $usdPayable->entries()->where('type', 'debit')->sum('amount');

    expect($pkrBalance)->toBe(6000)
        ->and($usdBalance)->toBe(500);
});