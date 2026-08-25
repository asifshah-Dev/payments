<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Services\LedgerPostingService;

it('correctly posts a partial payout, leaving a remaining positive balance in merchant payable', function () {
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

    // Verify initial payable is 9,800
    $initialPayable = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                    - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($initialPayable)->toBe(9800);

    // 3. Post Partial Payout (e.g., withdrawing 4,000 PKR out of 9,800)
    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 4000,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);

    $payoutTransaction = $postingService->postFromPayout($payout);

    expect($payoutTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($payoutTransaction->amount)->toBe(4000);

    // 4. Verify Resulting Balances
    // Merchant Payable should now be 9,800 - 4,000 = 5,800 PKR
    $remainingPayable = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                      - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($remainingPayable)->toBe(5800);

    // Clearing asset account should reduce by 4,000
    $netClearing = (int) $platformClearing->entries()->where('type', 'debit')->sum('amount') 
                 - (int) $platformClearing->entries()->where('type', 'credit')->sum('amount');
    // Initial: +10,000 debit. After payout: -4,000 credit. Net clearing balance = 6,000.
    expect($netClearing)->toBe(6000);
});