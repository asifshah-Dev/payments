<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Services\LedgerPostingService;

it('completes the full lifecycle with platform fees, payout settlement, and zero net payable balance', function () {
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

    // 2. Step A: Customer Payment with Fee (Gross: 10,000, Fee: 200, Net to Merchant: 9,800)
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

    $paymentTransaction = app(LedgerPostingService::class)->postFromPaymentAttempt($paymentAttempt);

    // Verify payment transaction structure (3 entries: 1 debit, 2 credits)
    expect($paymentTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($paymentTransaction->amount)->toBe(10000)
        ->and($paymentTransaction->entries)->toHaveCount(3);

    // Verify Merchant Payable balance after payment is +9,800 (Liability increases via credit)
    $payableCreditsAfterPayment = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount');
    $payableDebitsAfterPayment = (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($payableCreditsAfterPayment - $payableDebitsAfterPayment)->toBe(9800);

    // Verify Platform Fee Revenue balance is +200
    $revenueCredits = (int) $platformFeeRevenue->entries()->where('type', 'credit')->sum('amount');
    expect($revenueCredits)->toBe(200);

    // 3. Step B: Merchant Payout / Settlement for the net payable amount (9,800)
    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 9800,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);

    $payoutTransaction = app(LedgerPostingService::class)->postFromPayout($payout);

    // Verify Payout transaction structure (2 entries: debit merchant payable, credit clearing)
    expect($payoutTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($payoutTransaction->amount)->toBe(9800)
        ->and($payoutTransaction->entries)->toHaveCount(2);

    $payoutDebit = $payoutTransaction->entries->where('type', 'debit')->first();
    expect($payoutDebit->ledger_account_id)->toBe($merchantPayable->id)
        ->and($payoutDebit->amount)->toBe(9800);

    // 4. Step C: Final Invariants & Balance Verification
    $finalCredits = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount');
    $finalDebits = (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');

    // Merchant payable balance must return precisely to zero after settlement
    $netMerchantBalance = $finalCredits - $finalDebits;
    expect($netMerchantBalance)->toBe(0);

    // Platform fee revenue must remain fully intact and retained
    $finalRevenueCredits = (int) $platformFeeRevenue->entries()->where('type', 'credit')->sum('amount');
    expect($finalRevenueCredits)->toBe(200);
});