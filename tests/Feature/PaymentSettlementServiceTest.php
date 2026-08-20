<?php

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);


function createSettlementPaymentAttempt(
    string $status = 'succeeded'
): PaymentAttempt {
    $merchant = Merchant::create([
        'name' => 'Settlement Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $paymentIntent = PaymentIntent::create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Settlement test payment',
        'status' => $status,
        'idempotency_key' => Str::uuid()->toString(),
        'request_hash' => hash('sha256', Str::random(20)),
    ]);

    return PaymentAttempt::create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => $status,
        'amount' => 5000,
        'currency' => 'USD',
    ]);
}


function createSettlementAccounts(): array
{
    return [
        'debit' => LedgerAccount::create([
            'name' => 'USD Clearing',
            'type' => 'asset',
            'currency' => 'USD',
            'status' => 'active',
        ]),

        'credit' => LedgerAccount::create([
            'name' => 'USD Merchant Payable',
            'type' => 'liability',
            'currency' => 'USD',
            'status' => 'active',
        ]),
    ];
}


test('settles a succeeded payment attempt', function () {
    $attempt = createSettlementPaymentAttempt();

    createSettlementAccounts();

    $transaction = app(PaymentSettlementService::class)->settle(
        paymentAttempt: $attempt,
    );

    expect($transaction)
        ->toBeInstanceOf(LedgerTransaction::class);

    expect($transaction->type)
        ->toBe('payment');

    expect($transaction->payment_attempt_id)
        ->toBe($attempt->id);

    expect(LedgerTransaction::count())
        ->toBe(1);

    expect(LedgerEntry::count())
        ->toBe(2);
});


test('creates one debit and one credit entry during settlement', function () {
    $attempt = createSettlementPaymentAttempt();

    createSettlementAccounts();

    app(PaymentSettlementService::class)->settle(
        paymentAttempt: $attempt,
    );

    $entries = LedgerEntry::all();

    expect($entries)
        ->toHaveCount(2);

    expect($entries->where('type', 'debit'))
        ->toHaveCount(1);

    expect($entries->where('type', 'credit'))
        ->toHaveCount(1);
});


test('uses the payment amount and currency during settlement', function () {
    $attempt = createSettlementPaymentAttempt();

    createSettlementAccounts();

    $transaction = app(PaymentSettlementService::class)->settle(
        paymentAttempt: $attempt,
    );

    expect($transaction->amount)
        ->toBe($attempt->amount);

    expect($transaction->currency)
        ->toBe($attempt->currency);

    $entries = LedgerEntry::all();

    expect($entries->every(
        fn ($entry) =>
            $entry->amount === $attempt->amount &&
            $entry->currency === $attempt->currency
    ))->toBeTrue();
});


test('rejects a payment attempt that is not succeeded', function () {
    $attempt = createSettlementPaymentAttempt('processing');

    /*
     * Accounts are irrelevant because the status check
     * must happen before account resolution.
     */
    createSettlementAccounts();

    expect(fn () =>
        app(PaymentSettlementService::class)->settle(
            paymentAttempt: $attempt,
        )
    )->toThrow(RuntimeException::class);

    expect(LedgerTransaction::count())
        ->toBe(0);

    expect(LedgerEntry::count())
        ->toBe(0);
});


test('prevents duplicate settlement for the same payment attempt', function () {
    $attempt = createSettlementPaymentAttempt();

    createSettlementAccounts();

    $service = app(PaymentSettlementService::class);

    $first = $service->settle(
        paymentAttempt: $attempt,
    );

    $second = $service->settle(
        paymentAttempt: $attempt,
    );

    expect($first->id)
        ->toBe($second->id);

    expect(LedgerTransaction::count())
        ->toBe(1);

    expect(LedgerEntry::count())
        ->toBe(2);
});


test('stores payment attempt as the ledger reference', function () {
    $attempt = createSettlementPaymentAttempt();

    createSettlementAccounts();

    $transaction = app(PaymentSettlementService::class)->settle(
        paymentAttempt: $attempt,
    );

    expect($transaction->reference_type)
        ->toBe('payment_attempt');

    expect($transaction->reference_id)
        ->toBe($attempt->id);
});


test('does not settle when ledger currencies do not match payment currency', function () {
    $attempt = createSettlementPaymentAttempt();

    /*
     * USD debit account exists.
     */
    LedgerAccount::create([
        'name' => 'USD Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    /*
     * But the only liability account is EUR.
     *
     * Therefore the resolver cannot find a valid USD
     * debit + credit pair.
     */
    LedgerAccount::create([
        'name' => 'EUR Payable',
        'type' => 'liability',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    expect(fn () =>
        app(PaymentSettlementService::class)->settle(
            paymentAttempt: $attempt,
        )
    )->toThrow(RuntimeException::class);

    expect(LedgerTransaction::count())
        ->toBe(0);

    expect(LedgerEntry::count())
        ->toBe(0);
});