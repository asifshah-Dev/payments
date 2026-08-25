<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Services\LedgerPostingService;

it('correctly posts a chargeback reversal when the merchant wins a dispute, restoring their balance', function () {
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

    // 3. Post Payout (9,800 PKR) -> Payable returns to 0
    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 9800,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);
    $postingService->postFromPayout($payout);

    // 4. Post Chargeback (10,000 PKR) -> Payable becomes -10,000 (receivable)
    $postingService->postChargebackFromPaymentAttempt($paymentAttempt, 10000);

    $payableBalanceAfterChargeback = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                                   - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($payableBalanceAfterChargeback)->toBe(-10000);

    // 5. Post Chargeback Reversal / Win (10,000 PKR returned by bank)
    // Expected: Debit Clearing 10,000, Credit Merchant Payable 10,000
    $reversalTransaction = $postingService->postChargebackReversalFromPaymentAttempt($paymentAttempt, 10000);

    expect($reversalTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($reversalTransaction->amount)->toBe(10000);

    // 6. Verify Resulting Balances
    // Merchant Payable should return cleanly back to 0
    $finalPayableBalance = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                         - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($finalPayableBalance)->toBe(0);
});