<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\LedgerTransaction;
use App\Services\LedgerPostingService;

it('allows a merchant to payout an amount within their available payable balance', function () {
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

    $postingService = app(LedgerPostingService::class);

    // 2. Post Initial Payment (Gross: 10,000, Fee: 200, Net Payable: 9,800)
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

    $postingService->postFromPaymentAttempt($paymentAttempt);

    // 3. Process a valid payout within the limit (e.g., 5,000 PKR out of 9,800 available)
    $validPayout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);

    $transaction = $postingService->postFromPayout($validPayout);

    // 4. Assertions
    expect($transaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($transaction->type)->toBe('merchant_payout')
        ->and($transaction->amount)->toBe(5000);

    // Verify entries are linked correctly
    expect($transaction->entries)->toHaveCount(2);
});