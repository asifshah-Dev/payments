<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Payout;
use App\Services\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('correctly posts a payout in a non-default currency with exact debit and credit entries', function () {
    $merchant = Merchant::factory()->create();

    // 1. Setup USD accounts
    $usdClearing = LedgerAccount::create([
        'name' => 'Platform Clearing USD',
        'type' => 'asset',
        'currency' => 'USD',
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

    // 2. Fund the USD account with 1,500 USD
    DB::transaction(function () use ($postingService, $usdClearing, $usdPayable) {
        $postingService->post(
            type: 'funding_usd',
            amount: 1500,
            currency: 'USD',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => $usdClearing->id, 'type' => 'debit', 'amount' => 1500, 'currency' => 'USD'],
                ['ledger_account_id' => $usdPayable->id, 'type' => 'credit', 'amount' => 1500, 'currency' => 'USD'],
            ],
            referenceType: 'funding',
            referenceId: (string) Str::uuid(),
            description: 'Initial USD funding'
        );
    });

    // 3. Create a USD payout of 400 USD
    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 400,
        'currency' => 'USD',
        'status' => 'completed',
    ]);

    // 4. Post the payout
    $transaction = $postingService->postFromPayout($payout);

    // 5. Assertions
    expect($transaction)->not->toBeNull()
        ->and($transaction->currency)->toBe('USD')
        ->and($transaction->amount)->toBe(400);

    // Verify USD balance: 1,500 - 400 = 1,100 USD
    $usdBalance = (int) $usdPayable->entries()->where('type', 'credit')->sum('amount') 
                - (int) $usdPayable->entries()->where('type', 'debit')->sum('amount');

    expect($usdBalance)->toBe(1100);
});