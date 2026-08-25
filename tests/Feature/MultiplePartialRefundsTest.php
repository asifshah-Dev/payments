<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;

it('supports multiple sequential partial refunds and prevents over-refunding beyond the original amount', function () {
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

    $postingService = app(LedgerPostingService::class);
    $postingService->postFromPaymentAttempt($paymentAttempt);

    // 3. Refund #1: 3,000 PKR (Fee reversal: 60, Payable debit: 2,940)
    $refund1 = $postingService->postRefundFromPaymentAttempt($paymentAttempt, 3000);
    expect($refund1->amount)->toBe(3000);

    // 4. Refund #2: 2,000 PKR (Fee reversal: 40, Payable debit: 1,960)
    $refund2 = $postingService->postRefundFromPaymentAttempt($paymentAttempt, 2000);
    expect($refund2->amount)->toBe(2000);

    // 5. Refund #3: 5,000 PKR (Brings total refunded to exactly 10,000)
    $refund3 = $postingService->postRefundFromPaymentAttempt($paymentAttempt, 5000);
    expect($refund3->amount)->toBe(5000);

    // Verify all balances have returned precisely to zero
    $netPayable = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    $netRevenue = (int) $platformFeeRevenue->entries()->where('type', 'credit')->sum('amount') 
                - (int) $platformFeeRevenue->entries()->where('type', 'debit')->sum('amount');
    $netClearing = (int) $platformClearing->entries()->where('type', 'debit')->sum('amount') 
                 - (int) $platformClearing->entries()->where('type', 'credit')->sum('amount');

    expect($netPayable)->toBe(0)
        ->and($netRevenue)->toBe(0)
        ->and($netClearing)->toBe(0);

    // 6. Attempting any further refund should be rejected because 0 remains refundable
    expect(fn () => $postingService->postRefundFromPaymentAttempt($paymentAttempt, 100))
        ->toThrow(InvalidArgumentException::class);
});