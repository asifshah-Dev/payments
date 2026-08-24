<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerAccountMapping;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;
use InvalidArgumentException;
use RuntimeException;

it('creates three ledger entries for a payment with a fee and correct balance splitting', function () {
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

    LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
        'fee_account_role' => 'platform_fee_revenue',
    ]);

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
        'fee_amount' => 200, // PKR 200 fee
    ]);

    $transaction = app(LedgerPostingService::class)->postFromPaymentAttempt($paymentAttempt);

    expect($transaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($transaction->amount)->toBe(10000);

    $entries = $transaction->entries;
    expect($entries)->toHaveCount(3);

    $debitClearing = $entries->where('type', 'debit')->first();
    $creditPayable = $entries->where('type', 'credit')->where('ledger_account_id', $merchantPayable->id)->first();
    $creditFee = $entries->where('type', 'credit')->where('ledger_account_id', $platformFeeRevenue->id)->first();

    // 1. Debit equals gross payment amount (10,000)
    expect($debitClearing->ledger_account_id)->toBe($platformClearing->id)
        ->and($debitClearing->amount)->toBe(10000);

    // 2. Merchant payable equals gross amount minus fee (9,800)
    expect($creditPayable->amount)->toBe(9800);

    // 3. Platform fee revenue equals the fee (200)
    expect($creditFee->amount)->toBe(200);

    // 4. Debits and credits remain balanced (10,000 = 9,800 + 200)
    expect($entries->where('type', 'debit')->sum('amount'))
        ->toBe($entries->where('type', 'credit')->sum('amount'));
});

it('supports zero fee payments with standard clearing and payable entries', function () {
    $merchant = Merchant::factory()->create();

    LedgerAccount::create([
        'name' => 'Platform Gateway Clearing',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);

    $paymentIntent = PaymentIntent::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'PKR',
    ]);

    $paymentAttempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'amount' => 5000,
        'currency' => 'PKR',
        'status' => 'succeeded',
        'fee_amount' => 0,
    ]);

    $transaction = app(LedgerPostingService::class)->postFromPaymentAttempt($paymentAttempt);

    expect($transaction->entries)->toHaveCount(2)
        ->and($transaction->entries->sum('amount'))->toBe(10000); // Gross + Net
});

it('rejects negative fees', function () {
    $merchant = Merchant::factory()->create();

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
        'fee_amount' => -100,
    ]);

    expect(fn () => app(LedgerPostingService::class)->postFromPaymentAttempt($paymentAttempt))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects fees exceeding the payment amount', function () {
    $merchant = Merchant::factory()->create();

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
        'fee_amount' => 15000, // Fee > Amount
    ]);

    expect(fn () => app(LedgerPostingService::class)->postFromPaymentAttempt($paymentAttempt))
        ->toThrow(InvalidArgumentException::class);
});

it('prevents posting the same payment twice with its fee', function () {
    $merchant = Merchant::factory()->create();

    LedgerAccount::create([
        'name' => 'Platform Clearing',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);

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

    $service = app(LedgerPostingService::class);

    // First post succeeds
    $service->postFromPaymentAttempt($paymentAttempt);

    // Second post must throw duplicate/idempotency exception
    expect(fn () => $service->postFromPaymentAttempt($paymentAttempt))
        ->toThrow(RuntimeException::class);
});