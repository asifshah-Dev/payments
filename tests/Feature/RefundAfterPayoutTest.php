<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Services\LedgerPostingService;

it('handles a refund after merchant payout by placing the merchant payable into a negative receivable balance', function () {
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

    // Verify payable is +9,800
    $payableBalance = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                    - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($payableBalance)->toBe(9800);

    // 3. Post Merchant Payout for the full net payable amount (9,800)
    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 9800,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);

    $postingService->postFromPayout($payout);

    // Verify payable returns to zero after payout
    $payableBalanceAfterPayout = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                               - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($payableBalanceAfterPayout)->toBe(0);

    // 4. Post Full Refund (10,000 PKR) AFTER the payout has occurred
    $refundTransaction = $postingService->postRefundFromPaymentAttempt($paymentAttempt, 10000);

    // Verify refund transaction structure
    expect($refundTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($refundTransaction->amount)->toBe(10000)
        ->and($refundTransaction->entries)->toHaveCount(3);

    // 5. Verify Resulting Balances
    // Merchant Payable should now be negative (-9,800), representing a debt/receivable from the merchant
    $finalPayableCredits = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount');
    $finalPayableDebits = (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    $netMerchantPayable = $finalPayableCredits - $finalPayableDebits;

    expect($netMerchantPayable)->toBe(-9800);

    // Fee revenue should be fully reversed back to zero
    $netRevenue = (int) $platformFeeRevenue->entries()->where('type', 'credit')->sum('amount') 
                - (int) $platformFeeRevenue->entries()->where('type', 'debit')->sum('amount');
    expect($netRevenue)->toBe(0);
});