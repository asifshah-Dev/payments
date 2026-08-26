<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Payout;
use App\Services\LedgerPostingService;

it('fails when attempting a payout in a currency the merchant has no account for', function () {
    $merchant = Merchant::factory()->create();

    // 1. Setup ONLY a PKR account for the merchant (intentionally omitting USD accounts)
    LedgerAccount::create([
        'name' => 'Merchant Payable PKR',
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Clearing PKR',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    $postingService = app(LedgerPostingService::class);

    // 2. Trigger a USD Payout when the merchant has zero USD accounts
    $usdPayout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 100,
        'currency' => 'USD',
        'status' => 'completed',
    ]);

    // 3. Expect it to throw an exception because the currency-specific account mapping fails safely
    expect(fn () => $postingService->postFromPayout($usdPayout))
        ->toThrow(RuntimeException::class, 'No account mapping found for this payout context.');
});