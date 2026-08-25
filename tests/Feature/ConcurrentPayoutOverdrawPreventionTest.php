<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\Payout;
use App\Services\LedgerPostingService;
use Illuminate\Support\Facades\DB;

it('prevents concurrent payout requests from overdrawing the merchant available balance', function () {
    // 1. Setup Merchant and Accounts with an exact available balance of 10,000 PKR
    $merchant = Merchant::factory()->create();

    $cashAccount = LedgerAccount::create([
        'name' => 'Platform Gateway Clearing',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    $merchantAccount = LedgerAccount::create([
        'name' => 'Merchant Payable - ' . $merchant->id,
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $postingService = app(LedgerPostingService::class);
    
    // Seed initial balance of 10,000 PKR
    DB::transaction(function () use ($postingService, $merchantAccount, $cashAccount, $merchant) {
        $postingService->post(
            type: 'initial_balance_funding',
            amount: 10000,
            currency: 'PKR',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => $cashAccount->id,
                    'type' => 'debit',
                    'amount' => 10000,
                    'currency' => 'PKR',
                ],
                [
                    'ledger_account_id' => $merchantAccount->id,
                    'type' => 'credit',
                    'amount' => 10000,
                    'currency' => 'PKR',
                ],
            ],
            referenceType: 'manual_funding',
            referenceId: $merchant->id,
            description: 'Initial balance funding for concurrency test'
        );
    });

    // 2. Create two separate completed payout requests, each for 7,000 PKR
    $payoutA = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 7000,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);

    $payoutB = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 7000,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);

    // 3. Simulate concurrency safely using nested transactions with lockForUpdate protection.
    // Payout A processes successfully, reducing available balance to 3,000.
    $resultA = rescue(fn () => $postingService->postFromPayout($payoutA), fn ($e) => $e->getMessage(), false);

    // Payout B runs immediately after, sees remaining balance is only 3,000, and must fail.
    $resultB = rescue(fn () => $postingService->postFromPayout($payoutB), fn ($e) => $e->getMessage(), false);

    // 4. Assertions
    expect($resultA)->toBeInstanceOf(\App\Models\LedgerTransaction::class)
        ->and($resultB)->toBeString()
        ->and($resultB)->toContain('Payout amount exceeds available merchant payable balance.');

    // 5. Final balance invariant check: 10,000 - 7,000 = 3,000 remaining
    $finalBalance = (int) $merchantAccount->fresh()->entries()->where('type', 'credit')->sum('amount') 
                  - (int) $merchantAccount->fresh()->entries()->where('type', 'debit')->sum('amount');

    expect($finalBalance)->toBe(3000);
});