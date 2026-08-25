<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Payout;
use App\Services\LedgerPostingService;

it('prevents posting a payout with a currency that does not match the merchant account', function () {
    // 1. Setup Accounts in PKR
    $merchant = Merchant::factory()->create();

    LedgerAccount::create([
        'name' => 'Platform Gateway Clearing',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Payable - ' . $merchant->id,
        'type' => 'liability',
        'currency' => 'PKR', // Account is PKR
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $postingService = app(LedgerPostingService::class);

    // 2. Create a Payout requested in USD
    $mismatchedPayout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 500,
        'currency' => 'USD', // Mismatched currency
        'status' => 'completed',
    ]);

    // 3. Expect it to fail due to account mapping or currency mismatch failure
    expect(fn () => $postingService->postFromPayout($mismatchedPayout))
        ->toThrow(RuntimeException::class);
});