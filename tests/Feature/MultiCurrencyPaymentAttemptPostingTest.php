<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;

it('correctly posts a payment attempt in a non-default currency using currency-specific accounts', function () {
    $merchant = Merchant::factory()->create();

    // 1. Setup USD accounts
    $usdClearing = LedgerAccount::create([
        'name' => 'Platform Gateway Clearing USD',
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

    $usdFeeRevenue = LedgerAccount::create([
        'name' => 'Platform Fee Revenue USD',
        'type' => 'revenue',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $postingService = app(LedgerPostingService::class);

    // 2. Create Payment Intent and Attempt in USD (e.g., 1,000 USD gross, 30 USD fee)
    $paymentIntent = PaymentIntent::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $paymentAttempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'amount' => 1000,
        'currency' => 'USD',
        'status' => 'succeeded',
        'fee_amount' => 30,
    ]);

    // 3. Post from payment attempt
    $transaction = $postingService->postFromPaymentAttempt($paymentAttempt);

    // 4. Assertions
    expect($transaction)->not->toBeNull()
        ->and($transaction->currency)->toBe('USD')
        ->and($transaction->amount)->toBe(1000);

    // Verify USD Merchant Payable balance is credited with net amount (1000 - 30 = 970 USD)
    $usdBalance = (int) $usdPayable->entries()->where('type', 'credit')->sum('amount') 
                - (int) $usdPayable->entries()->where('type', 'debit')->sum('amount');

    expect($usdBalance)->toBe(970);
});