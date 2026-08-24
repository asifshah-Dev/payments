<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerAccountMapping;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent; // Assuming PaymentIntent exists
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;

it('automatically posts a balanced ledger transaction when a payment attempt succeeds end-to-end', function () {
    // 1. Set up the Merchant and their specific Payable Account (Liability)
    $merchant = Merchant::factory()->create();

    $merchantPayable = LedgerAccount::create([
        'name' => 'Merchant Payable - ' . $merchant->id,
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    // 2. Set up the Platform Gateway Clearing Account (Asset, merchant_id = NULL)
    $platformClearing = LedgerAccount::create([
        'name' => 'Platform Gateway Clearing',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    // 3. Set up the Ledger Account Mapping for successful payments in PKR
    LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);

    // 4. Create a Payment Intent belonging to the merchant, then the Payment Attempt
    $paymentIntent = PaymentIntent::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 15000,
        'currency' => 'PKR',
    ]);

    $paymentAttempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'amount' => 15000,
        'currency' => 'PKR',
        'status' => 'succeeded',
    ]);

    // 5. Trigger the posting service using the payment attempt
    $transaction = app(LedgerPostingService::class)->postFromPaymentAttempt($paymentAttempt);

    // 6. End-to-End Assertions
    expect($transaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($transaction->payment_attempt_id)->toBe($paymentAttempt->id)
        ->and($transaction->amount)->toBe(15000)
        ->and($transaction->currency)->toBe('PKR')
        ->and($transaction->posted_at)->not->toBeNull();

    // Verify entries count and balance
    $entries = $transaction->entries;
    expect($entries)->toHaveCount(2);

    $debitEntry = $entries->where('type', 'debit')->first();
    $creditEntry = $entries->where('type', 'credit')->first();

    // Debit goes to Platform Gateway Clearing (Asset increases)
    expect($debitEntry->ledger_account_id)->toBe($platformClearing->id)
        ->and($debitEntry->amount)->toBe(15000)
        ->and($debitEntry->currency)->toBe('PKR');

    // Credit goes to Merchant Payable (Liability increases)
    expect($creditEntry->ledger_account_id)->toBe($merchantPayable->id)
        ->and($creditEntry->amount)->toBe(15000)
        ->and($creditEntry->currency)->toBe('PKR');

    // Ensure double-entry balance integrity
    expect($entries->where('type', 'debit')->sum('amount'))
        ->toBe($entries->where('type', 'credit')->sum('amount'));
});