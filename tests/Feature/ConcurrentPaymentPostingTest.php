<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;
use Illuminate\Support\Facades\DB;

it('guarantees exactly one ledger transaction is created under concurrent posting attempts', function () {
    // 1. Setup Accounts
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
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Platform Fee Revenue',
        'type' => 'revenue',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    $paymentIntent = PaymentIntent::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 10000,
        'currency' => 'PKR',
    ]);

    $paymentAttempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'amount' => 10000,
        'currency' => 'PKR',
        'status' => 'succeeded',
        'fee_amount' => 200,
    ]);

    $postingService = app(LedgerPostingService::class);

    // Simulate rapid concurrent calls by catching exceptions from duplicate attempts
    $successCount = 0;
    $attempts = 5;

    for ($i = 0; $i < $attempts; $i++) {
        try {
            $postingService->postFromPaymentAttempt($paymentAttempt);
            $successCount++;
        } catch (\RuntimeException $e) {
            // Expected duplicate prevention exception
        }
    }

    // Exactly one call should have succeeded
    expect($successCount)->toBe(1);

    // Exactly one transaction should exist in the database
    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(1);
});