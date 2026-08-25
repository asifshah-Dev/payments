<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;

it('correctly posts a chargeback fee, debiting the merchant and crediting platform revenue', function () {
    // 1. Setup Accounts
    $merchant = Merchant::factory()->create();

    $platformClearing = LedgerAccount::create([
        'name' => 'Platform Gateway Clearing',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    $merchantPayable = LedgerAccount::create([
        'name' => 'Merchant Payable - ' . $merchant->id,
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $platformFeeRevenue = LedgerAccount::create([
        'name' => 'Platform Fee Revenue',
        'type' => 'revenue',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    $postingService = app(LedgerPostingService::class);

    // 2. Post Initial Payment (Gross: 10,000, Fee: 200, Net: 9,800)
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

    // Initial merchant payable should be 9,800
    $initialPayable = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                    - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($initialPayable)->toBe(9800);

    // 3. Post Chargeback Fee (e.g., 500 PKR dispute fee)
    // Expected: Debit Merchant Payable 500, Credit Platform Fee Revenue 500
    $feeAmount = 500;
    $feeTransaction = $postingService->postChargebackFeeFromPaymentAttempt($paymentAttempt, $feeAmount);

    expect($feeTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($feeTransaction->amount)->toBe($feeAmount)
        ->and($feeTransaction->entries)->toHaveCount(2);

    // 4. Verify Resulting Balances
    // Merchant payable should reduce by the fee amount (9,800 - 500 = 9,300)
    $netMerchantPayable = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                        - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($netMerchantPayable)->toBe(9300);

    // Platform fee revenue should increase by the dispute fee (200 + 500 = 700)
    $netRevenue = (int) $platformFeeRevenue->entries()->where('type', 'credit')->sum('amount') 
                - (int) $platformFeeRevenue->entries()->where('type', 'debit')->sum('amount');
    expect($netRevenue)->toBe(700);
});