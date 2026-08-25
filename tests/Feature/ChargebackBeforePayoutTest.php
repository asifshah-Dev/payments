<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;

it('correctly posts a chargeback before payout by reducing the merchant payable instead of clearing', function () {
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

    // Verify merchant payable is holding +9,800 and clearing holds +10,000
    expect((int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
         - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount'))->toBe(9800);

    // 3. Post Chargeback BEFORE Payout (Full amount: 10,000 PKR)
    // Note: Since fee was already taken, handling a full gross chargeback requires 
    // debiting the merchant payable for their net share and clearing/revenue appropriately, 
    // or routing the full dispute adjustment. Let's see how our service handles it or update it.
    
    // For a pre-payout chargeback of the full gross amount (10,000):
    // - Platform clearing drops by 10,000 (bank takes it back)
    // - Merchant payable drops by 9,800 (withholding what they were owed)
    // - Fee revenue drops/reverses by 200 (since the processor refunded or waived the fee on chargeback)
    
    $chargebackTransaction = $postingService->postPrePayoutChargebackFromPaymentAttempt($paymentAttempt, 10000);

    expect($chargebackTransaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($chargebackTransaction->amount)->toBe(10000);

    // 4. Verify Final Balances
    // Merchant payable should be completely wiped back to 0
    $netMerchantPayable = (int) $merchantPayable->entries()->where('type', 'credit')->sum('amount') 
                        - (int) $merchantPayable->entries()->where('type', 'debit')->sum('amount');
    expect($netMerchantPayable)->toBe(0);

    // Platform clearing net change should be 0 (inflow of 10k cancelled by chargeback outflow of 10k)
    $netClearing = (int) $platformClearing->entries()->where('type', 'credit')->sum('amount') 
                 - (int) $platformClearing->entries()->where('type', 'debit')->sum('amount');
    expect($netClearing)->toBe(0);

    // Fee revenue should be reversed back to 0 because the processor took back the transaction
    $netRevenue = (int) $platformFeeRevenue->entries()->where('type', 'credit')->sum('amount') 
                - (int) $platformFeeRevenue->entries()->where('type', 'debit')->sum('amount');
    expect($netRevenue)->toBe(0);
});