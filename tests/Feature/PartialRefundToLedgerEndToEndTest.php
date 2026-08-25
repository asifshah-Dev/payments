<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;

it('correctly calculates and posts a partial refund with pro-rata fee reversal', function () {
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

    $postingService = app(LedgerPostingService::class);
    $postingService->postFromPaymentAttempt($paymentAttempt);

    // Verify initial merchant payable is 9,800 and revenue is 200
    $initialPayable = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                    - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($initialPayable)->toBe(9800);

    // 3. Post Partial Refund of 4,000 PKR
    // Expected pro-rata fee refund: (4,000 / 10,000) * 200 = 80 PKR
    // Expected net debit to merchant payable: 4,000 - 80 = 3,920 PKR
    $refundTransaction = $postingService->postRefundFromPaymentAttempt($paymentAttempt, 4000);

    // 4. Verify Partial Refund Transaction Structure
    expect($refundTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($refundTransaction->amount)->toBe(4000)
        ->and($refundTransaction->entries)->toHaveCount(3);

    $debitPayable = $refundTransaction->entries->where('type', 'debit')->where('ledger_account_id', $merchantPayable->id)->first();
    $debitRevenue = $refundTransaction->entries->where('type', 'debit')->where('ledger_account_id', $platformFeeRevenue->id)->first();
    $creditClearing = $refundTransaction->entries->where('type', 'credit')->where('ledger_account_id', $platformClearing->id)->first();

    expect($debitPayable->amount)->toBe(3920)
        ->and($debitRevenue->amount)->toBe(80)
        ->and($creditClearing->amount)->toBe(4000);

    // 5. Verify Remaining Balances
    // Merchant Payable should now be: 9,800 - 3,920 = 5,880
    $remainingPayable = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                      - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($remainingPayable)->toBe(5880);

    // Fee Revenue should now be: 200 - 80 = 120
    $remainingRevenue = (int) $platformFeeRevenue->entries()->where('type', 'credit')->sum('amount') 
                      - (int) $platformFeeRevenue->entries()->where('type', 'debit')->sum('amount');
    expect($remainingRevenue)->toBe(120);
});