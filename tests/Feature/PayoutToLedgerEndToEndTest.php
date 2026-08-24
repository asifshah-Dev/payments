<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerAccountMapping;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Services\LedgerPostingService;

it('completes the full money lifecycle from customer payment to merchant payout returning balance to zero', function () {
    // 1. Setup Accounts and Mappings
    $merchant = Merchant::factory()->create();

    $merchantPayable = LedgerAccount::create([
        'name' => 'Merchant Payable - ' . $merchant->id,
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $platformClearing = LedgerAccount::create([
        'name' => 'Platform Gateway Clearing',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);

    LedgerAccountMapping::create([
        'context' => 'merchant_payout',
        'currency' => 'PKR',
        'debit_account_role' => 'merchant_payable',
        'credit_account_role' => 'platform_gateway_clearing',
    ]);

    // 2. Step A: Customer Payment (Inflow)
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
    ]);

    $paymentTransaction = app(LedgerPostingService::class)->postFromPaymentAttempt($paymentAttempt);

    // Verify post-payment state
    expect($paymentTransaction->amount)->toBe(10000);

    $creditEntry = $paymentTransaction->entries->where('type', 'credit')->first();
    expect($creditEntry->ledger_account_id)->toBe($merchantPayable->id);

    // Verify merchant payable balance is +10,000 (Credits sum to 10k)
    $creditsAfterPayment = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount');
    $debitsAfterPayment = (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    
    expect($creditsAfterPayment)->toBe(10000);
    expect($debitsAfterPayment)->toBe(0);

    // 3. Step B: Merchant Payout / Settlement via real posting service
    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 10000,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);

    $payoutTransaction = app(LedgerPostingService::class)->postFromPayout($payout);

    // 4. Assertions on Payout Transaction
    expect($payoutTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($payoutTransaction->amount)->toBe(10000)
        ->and($payoutTransaction->currency)->toBe('PKR')
        ->and($payoutTransaction->posted_at)->not->toBeNull();

    $payoutEntries = $payoutTransaction->entries;
    expect($payoutEntries)->toHaveCount(2);

    $payoutDebit = $payoutEntries->where('type', 'debit')->first();
    $payoutCredit = $payoutEntries->where('type', 'credit')->first();

    // Payout debits Merchant Payable (reducing liability by 10,000)
    expect($payoutDebit->ledger_account_id)->toBe($merchantPayable->id)
        ->and($payoutDebit->amount)->toBe(10000);

    // Payout credits Platform Clearing (reducing asset / cash out by 10,000)
    expect($payoutCredit->ledger_account_id)->toBe($platformClearing->id)
        ->and($payoutCredit->amount)->toBe(10000);

    // 5. Final Invariants & Balance Verification (Net Zero Balance)
    $totalCredits = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount');
    $totalDebits = (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    
    expect($totalCredits)->toBe(10000);
    expect($totalDebits)->toBe(10000);

    // Net balance calculation for a liability account (Credits increase, Debits decrease)
    $netBalance = $totalCredits - $totalDebits;
    expect($netBalance)->toBe(0);
});