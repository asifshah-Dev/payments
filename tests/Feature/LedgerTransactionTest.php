<?php

use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function createLedgerPaymentAttempt(): PaymentAttempt
{
    dump(
    DB::connection()->getDatabaseName(),
    Schema::getColumnListing('ledger_transactions')
);
    $merchant = Merchant::create([
        'name' => 'Ledger Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $paymentIntent = PaymentIntent::create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Ledger test payment',
        'status' => 'processing',
        'idempotency_key' => Str::uuid()->toString(),
        'request_hash' => hash('sha256', Str::random(20)),
    ]);

    return PaymentAttempt::create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'succeeded',
        'amount' => 5000,
        'currency' => 'USD',
    ]);
}

test('creates a ledger transaction', function () {
    $attempt = createLedgerPaymentAttempt();

    $ledger = LedgerTransaction::create([
        'type' => 'payment',
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'credit',
        'payment_attempt_id' => $attempt->id,
        'description' => 'Payment received',
        'posted_at' => now(),
    ]);

    expect($ledger)->toBeInstanceOf(LedgerTransaction::class);

    expect($ledger->amount)->toBe(5000)
        ->and($ledger->currency)->toBe('USD')
        ->and($ledger->direction)->toBe('credit')
        ->and($ledger->type)->toBe('payment');
});

test('belongs to a payment attempt', function () {
    $attempt = createLedgerPaymentAttempt();

    $ledger = LedgerTransaction::create([
        'type' => 'payment',
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'credit',
        'payment_attempt_id' => $attempt->id,
        'posted_at' => now(),
    ]);

    expect($ledger->paymentAttempt)
        ->toBeInstanceOf(PaymentAttempt::class)
        ->and($ledger->paymentAttempt->id)
        ->toBe($attempt->id);
});

test('stores reference information', function () {
    $attempt = createLedgerPaymentAttempt();

    $referenceId = Str::uuid()->toString();

    $ledger = LedgerTransaction::create([
        'type' => 'payment',
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'credit',
        'payment_attempt_id' => $attempt->id,
        'reference_type' => 'payment_attempt',
        'reference_id' => $referenceId,
        'description' => 'Payment received',
        'posted_at' => now(),
    ]);

    expect($ledger->reference_type)->toBe('payment_attempt')
        ->and($ledger->reference_id)->toBe($referenceId);
});

test('supports debit ledger transactions', function () {
    $attempt = createLedgerPaymentAttempt();

    $ledger = LedgerTransaction::create([
        'type' => 'refund',
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'payment_attempt_id' => $attempt->id,
        'description' => 'Payment refund',
        'posted_at' => now(),
    ]);

    expect($ledger->direction)->toBe('debit')
        ->and($ledger->type)->toBe('refund');
});

test('stores posted at as a datetime', function () {
    $attempt = createLedgerPaymentAttempt();

    $postedAt = now();

    $ledger = LedgerTransaction::create([
        'type' => 'payment',
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'credit',
        'payment_attempt_id' => $attempt->id,
        'posted_at' => $postedAt,
    ]);

    expect($ledger->posted_at)->toBeInstanceOf(
        \Illuminate\Support\Carbon::class
    );
});

test('allows a ledger transaction without payment attempt', function () {
    $ledger = LedgerTransaction::create([
        'type' => 'adjustment',
        'amount' => 1000,
        'currency' => 'USD',
        'direction' => 'credit',
        'payment_attempt_id' => null,
        'description' => 'Manual adjustment',
        'posted_at' => now(),
    ]);

    expect($ledger->payment_attempt_id)->toBeNull();
});