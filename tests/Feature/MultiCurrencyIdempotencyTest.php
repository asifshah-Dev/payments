<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;

it('prevents posting the same non-default currency payment attempt twice (idempotency enforcement)', function () {
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

    // 2. Create Payment Intent and Attempt in USD
    $paymentIntent = PaymentIntent::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 500,
        'currency' => 'USD',
    ]);

    $paymentAttempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'amount' => 500,
        'currency' => 'USD',
        'status' => 'succeeded',
        'fee_amount' => 15,
    ]);

    // 3. Post the payment attempt the first time (should succeed)
    $firstTransaction = $postingService->postFromPaymentAttempt($paymentAttempt);
    expect($firstTransaction)->not->toBeNull();

    // 4. Attempt to post the exact same payment attempt a second time (webhook retry / duplicate)
    expect(fn () => $postingService->postFromPaymentAttempt($paymentAttempt))
        ->toThrow(RuntimeException::class, 'Payment attempt has already been posted to the ledger.');
});