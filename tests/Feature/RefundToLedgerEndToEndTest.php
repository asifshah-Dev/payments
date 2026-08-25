<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;

it('completes a full refund lifecycle, reversing merchant payable and fee revenue while keeping original payment immutable', function () {
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
    $originalPaymentTransaction = $postingService->postFromPaymentAttempt($paymentAttempt);

    expect($originalPaymentTransaction->entries)->toHaveCount(3);

    // 3. Post Full Refund (10,000 PKR refund against the payment attempt)
    $refundTransaction = $postingService->postRefundFromPaymentAttempt($paymentAttempt, 10000);

    // 4. Verify Refund Transaction Structure
    // Expected: Debit Merchant Payable (9,800), Debit Fee Revenue (200), Credit Platform Clearing (10,000)
    expect($refundTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($refundTransaction->amount)->toBe(10000)
        ->and($refundTransaction->entries)->toHaveCount(3);

    $debitPayable = $refundTransaction->entries->where('type', 'debit')->where('ledger_account_id', $merchantPayable->id)->first();
    $debitRevenue = $refundTransaction->entries->where('type', 'debit')->where('ledger_account_id', $platformFeeRevenue->id)->first();
    $creditClearing = $refundTransaction->entries->where('type', 'credit')->where('ledger_account_id', $platformClearing->id)->first();

    expect($debitPayable->amount)->toBe(9800)
        ->and($debitRevenue->amount)->toBe(200)
        ->and($creditClearing->amount)->toBe(10000);

    // 5. Verify Invariants (Net balances return to zero)
    $netMerchantBalance = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                        - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($netMerchantBalance)->toBe(0);

    $netRevenueBalance = (int) $platformFeeRevenue->entries()->where('type', 'credit')->sum('amount') 
                       - (int) $platformFeeRevenue->entries()->where('type', 'debit')->sum('amount');
    expect($netRevenueBalance)->toBe(0);

    $netClearingBalance = (int) $platformClearing->entries()->where('type', 'debit')->sum('amount') 
                        - (int) $platformClearing->entries()->where('type', 'credit')->sum('amount');
    expect($netClearingBalance)->toBe(0);

    // 6. Verify Immutability of Original Payment Transaction
    $originalPaymentTransaction->refresh();
    expect($originalPaymentTransaction->amount)->toBe(10000)
        ->and($originalPaymentTransaction->entries)->toHaveCount(3);
});