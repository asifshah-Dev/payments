
<?php

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\PaymentLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

function createPaymentLedgerTestAttempt(): PaymentAttempt
{
    $merchant = Merchant::create([
        'name' => 'Payment Ledger Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $paymentIntent = PaymentIntent::create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Payment ledger test',
        'status' => 'succeeded',
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

function createPaymentLedgerTestAccounts(): array
{
    $debitAccount = LedgerAccount::create([
        'name' => 'Processor Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $creditAccount = LedgerAccount::create([
        'name' => 'Merchant Balance',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    return [
        'debit' => $debitAccount,
        'credit' => $creditAccount,
    ];
}

test('posts a successful payment to the ledger', function () {
    $attempt = createPaymentLedgerTestAttempt();
    $accounts = createPaymentLedgerTestAccounts();

    $transaction = app(PaymentLedgerService::class)->postPayment(
        paymentAttempt: $attempt,
        debitAccount: $accounts['debit'],
        creditAccount: $accounts['credit'],
    );

    expect($transaction)
        ->toBeInstanceOf(LedgerTransaction::class);

    expect($transaction->payment_attempt_id)
        ->toBe($attempt->id);

    expect($transaction->type)
        ->toBe('payment');

    expect($transaction->amount)
        ->toBe(5000);

    expect($transaction->currency)
        ->toBe('USD');
});

test('creates exactly one debit and one credit entry for a payment', function () {
    $attempt = createPaymentLedgerTestAttempt();
    $accounts = createPaymentLedgerTestAccounts();

    $transaction = app(PaymentLedgerService::class)->postPayment(
        paymentAttempt: $attempt,
        debitAccount: $accounts['debit'],
        creditAccount: $accounts['credit'],
    );

    expect(LedgerEntry::where(
        'ledger_transaction_id',
        $transaction->id
    )->count())->toBe(2);

    $debit = LedgerEntry::where('ledger_transaction_id', $transaction->id)
        ->where('type', 'debit')
        ->first();

    $credit = LedgerEntry::where('ledger_transaction_id', $transaction->id)
        ->where('type', 'credit')
        ->first();

    expect($debit)->not->toBeNull()
        ->and($credit)->not->toBeNull()
        ->and($debit->amount)->toBe(5000)
        ->and($credit->amount)->toBe(5000);
});

test('uses the payment attempt currency and amount', function () {
    $attempt = createPaymentLedgerTestAttempt();
    $accounts = createPaymentLedgerTestAccounts();

    $transaction = app(PaymentLedgerService::class)->postPayment(
        paymentAttempt: $attempt,
        debitAccount: $accounts['debit'],
        creditAccount: $accounts['credit'],
    );

    expect($transaction->amount)
        ->toBe($attempt->amount)
        ->and($transaction->currency)
        ->toBe($attempt->currency);
});

test('rejects a payment attempt that is not succeeded', function () {
    $attempt = createPaymentLedgerTestAttempt();

    $attempt->update([
        'status' => 'processing',
    ]);

    $accounts = createPaymentLedgerTestAccounts();

    expect(fn () =>
        app(PaymentLedgerService::class)->postPayment(
            paymentAttempt: $attempt,
            debitAccount: $accounts['debit'],
            creditAccount: $accounts['credit'],
        )
    )->toThrow(RuntimeException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('prevents duplicate ledger posting for the same payment attempt', function () {
    $attempt = createPaymentLedgerTestAttempt();
    $accounts = createPaymentLedgerTestAccounts();

    $service = app(PaymentLedgerService::class);

    $first = $service->postPayment(
        paymentAttempt: $attempt,
        debitAccount: $accounts['debit'],
        creditAccount: $accounts['credit'],
    );

    $second = $service->postPayment(
        paymentAttempt: $attempt,
        debitAccount: $accounts['debit'],
        creditAccount: $accounts['credit'],
    );

    expect($first->id)
        ->toBe($second->id);

    expect(LedgerTransaction::where(
        'payment_attempt_id',
        $attempt->id
    )->count())->toBe(1);

    expect(LedgerEntry::where(
        'ledger_transaction_id',
        $first->id
    )->count())->toBe(2);
});

test('stores payment attempt as the ledger reference', function () {
    $attempt = createPaymentLedgerTestAttempt();
    $accounts = createPaymentLedgerTestAccounts();

    $transaction = app(PaymentLedgerService::class)->postPayment(
        paymentAttempt: $attempt,
        debitAccount: $accounts['debit'],
        creditAccount: $accounts['credit'],
    );

    expect($transaction->reference_type)
        ->toBe('payment_attempt')
        ->and($transaction->reference_id)
        ->toBe($attempt->id);
});

test('does not post when ledger accounts use different currencies', function () {
    $attempt = createPaymentLedgerTestAttempt();

    $debitAccount = LedgerAccount::create([
        'name' => 'USD Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $creditAccount = LedgerAccount::create([
        'name' => 'EUR Balance',
        'type' => 'liability',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    expect(fn () =>
        app(PaymentLedgerService::class)->postPayment(
            paymentAttempt: $attempt,
            debitAccount: $debitAccount,
            creditAccount: $creditAccount,
        )
    )->toThrow(RuntimeException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});
