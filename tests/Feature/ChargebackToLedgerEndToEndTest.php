<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Services\LedgerPostingService;

it('correctly posts a chargeback after a payout, increasing the merchant receivable and reducing clearing', function () {
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

    // 3. Post Payout (9,800 PKR) -> Merchant Payable returns to 0
    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 9800,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);
    $postingService->postFromPayout($payout);

    expect((int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
         - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount'))->toBe(0);

    // 4. Post Chargeback of 10,000 PKR
    // Expected: Debit Merchant Payable 10,000, Credit Platform Clearing 10,000
    $chargebackTransaction = $postingService->postChargebackFromPaymentAttempt($paymentAttempt, 10000);

    // 5. Verify Chargeback Transaction Structure
    expect($chargebackTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($chargebackTransaction->amount)->toBe(10000)
        ->and($chargebackTransaction->entries)->toHaveCount(2);

    $debitPayable = $chargebackTransaction->entries->where('type', 'debit')->where('ledger_account_id', $merchantPayable->id)->first();
    $creditClearing = $chargebackTransaction->entries->where('type', 'credit')->where('ledger_account_id', $platformClearing->id)->first();

    expect($debitPayable->amount)->toBe(10000)
        ->and($creditClearing->amount)->toBe(10000);

    // 6. Verify Resulting Balances
    // Merchant Payable should now be -10,000 (receivable)
    $netMerchantPayable = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                        - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($netMerchantPayable)->toBe(-10000);

    // Platform fee revenue should remain untouched (200), reflecting the platform's processed service before dispute
    $netRevenue = (int) $platformFeeRevenue->entries()->where('type', 'credit')->sum('amount') 
                - (int) $platformFeeRevenue->entries()->where('type', 'debit')->sum('amount');
    expect($netRevenue)->toBe(200);
});