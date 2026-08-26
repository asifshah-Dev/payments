<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Payout;
use App\Services\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('prevents overdrawing a specific currency balance even if the merchant has a massive balance in another currency', function () {
    $merchant = Merchant::factory()->create();

    // 1. Setup Clearing accounts
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

    // 2. Setup Merchant Payable accounts for both currencies
    $pkrPayable = LedgerAccount::create([
        'name' => 'Payable PKR',
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
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

    // 3. Fund PKR heavily (100,000 PKR)
    DB::transaction(function () use ($postingService, $pkrClearing, $pkrPayable) {
        $postingService->post(
            type: 'funding_pkr',
            amount: 100000,
            currency: 'PKR',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => $pkrClearing->id, 'type' => 'debit', 'amount' => 100000, 'currency' => 'PKR'],
                ['ledger_account_id' => $pkrPayable->id, 'type' => 'credit', 'amount' => 100000, 'currency' => 'PKR'],
            ],
            referenceType: 'funding',
            referenceId: (string) Str::uuid(),
            description: 'PKR funding'
        );
    });

    // 4. Fund USD sparsely (only 50 USD)
    DB::transaction(function () use ($postingService, $usdClearing, $usdPayable) {
        $postingService->post(
            type: 'funding_usd',
            amount: 50,
            currency: 'USD',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => $usdClearing->id, 'type' => 'debit', 'amount' => 50, 'currency' => 'USD'],
                ['ledger_account_id' => $usdPayable->id, 'type' => 'credit', 'amount' => 50, 'currency' => 'USD'],
            ],
            referenceType: 'funding',
            referenceId: (string) Str::uuid(),
            description: 'USD funding'
        );
    });

    // 5. Attempt a USD payout of 500 USD (exceeds the 50 USD balance, despite having 100k PKR)
    $excessiveUsdPayout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 500,
        'currency' => 'USD',
        'status' => 'completed',
    ]);

    // 6. Expect it to fail because the specific USD balance is insufficient
    expect(fn () => $postingService->postFromPayout($excessiveUsdPayout))
        ->toThrow(RuntimeException::class, 'Payout amount exceeds available merchant payable balance.');
});