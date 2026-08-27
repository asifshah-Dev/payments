<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Services\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('successfully reverses a posted ledger transaction with exact mirror entries', function () {
    $merchant = Merchant::factory()->create();

    // 1. Setup Accounts
    $clearing = LedgerAccount::create([
        'name' => 'Clearing PKR',
        'type' => 'asset',
        'currency' => 'PKR',
        'status' => 'active',
    ]);

    $payable = LedgerAccount::create([
        'name' => 'Merchant Payable PKR - ' . $merchant->id,
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $postingService = app(LedgerPostingService::class);

    // 2. Post an initial transaction (e.g., manual funding of 10,000 PKR)
    $transaction = DB::transaction(function () use ($postingService, $clearing, $payable) {
        return $postingService->post(
            type: 'manual_funding',
            amount: 10000,
            currency: 'PKR',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => $clearing->id, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'],
                ['ledger_account_id' => $payable->id, 'type' => 'credit', 'amount' => 10000, 'currency' => 'PKR'],
            ],
            referenceType: 'funding',
            referenceId: (string) Str::uuid(),
            description: 'Initial funding'
        );
    });

    // Verify balance is 10,000 PKR
    $balanceBeforeReversal = (int) $payable->entries()->where('type', 'credit')->sum('amount') 
                           - (int) $payable->entries()->where('type', 'debit')->sum('amount');
    expect($balanceBeforeReversal)->toBe(10000);

    // 3. Reverse the transaction
    $reversalTransaction = $postingService->reverse($transaction, 'Administrative correction / reversal');

    // 4. Assertions
    expect($reversalTransaction)->not->toBeNull()
        ->and($reversalTransaction->type)->toBe('manual_funding_reversal')
        ->and($reversalTransaction->amount)->toBe(10000);

    // The original transaction must still exist and be completely unmodified
    expect(DB::table('ledger_transactions')->where('id', $transaction->id)->exists())->toBeTrue();

    // The net balance across the accounts should now be back to 0
    $balanceAfterReversal = (int) $payable->entries()->where('type', 'credit')->sum('amount') 
                          - (int) $payable->entries()->where('type', 'debit')->sum('amount');
    expect($balanceAfterReversal)->toBe(0);
});