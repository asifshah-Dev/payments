<?php

use App\Models\IncomingWebhookLog;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\IncomingWebhookProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

function createWebhookSettlementAttempt(): PaymentAttempt
{
    $merchant = Merchant::create([
        'name' => 'Webhook Settlement Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $paymentIntent = PaymentIntent::create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Webhook settlement test',
        'status' => 'processing',
        'idempotency_key' => Str::uuid()->toString(),
        'request_hash' => hash('sha256', Str::random(20)),
    ]);

    return PaymentAttempt::create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'processing',
        'amount' => 5000,
        'currency' => 'USD',
    ]);
}

function createWebhookSettlementAccounts(): array
{
    return [
        'debit' => LedgerAccount::create([
            'name' => 'Stripe Clearing',
            'type' => 'asset',
            'currency' => 'USD',
            'status' => 'active',
        ]),

        'credit' => LedgerAccount::create([
            'name' => 'Merchant Payable',
            'type' => 'liability',
            'currency' => 'USD',
            'status' => 'active',
        ]),
    ];
}

function createSucceededWebhook(PaymentAttempt $attempt): IncomingWebhookLog
{
    return IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_success_' . Str::uuid(),
        'event_type' => 'payment.succeeded',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'received',
        'attempt_count' => 0,
        'received_at' => now(),
    ]);
}

test('successful webhook automatically settles the payment', function () {
    $attempt = createWebhookSettlementAttempt();

    createWebhookSettlementAccounts();

    $webhook = createSucceededWebhook($attempt);

    app(IncomingWebhookProcessingService::class)->process($webhook);

    $attempt->refresh();

    expect($attempt->status)
        ->toBe('succeeded');

    expect(LedgerTransaction::count())
        ->toBe(1);

    $transaction = LedgerTransaction::first();

    expect($transaction->payment_attempt_id)
        ->toBe($attempt->id);

    expect($transaction->amount)
        ->toBe($attempt->amount);

    expect($transaction->currency)
        ->toBe($attempt->currency);

    expect($transaction->type)
        ->toBe('payment');

    expect(LedgerEntry::count())
        ->toBe(2);

    expect(LedgerEntry::where('type', 'debit')->count())
        ->toBe(1);

    expect(LedgerEntry::where('type', 'credit')->count())
        ->toBe(1);
});

test('successful webhook creates a balanced ledger posting', function () {
    $attempt = createWebhookSettlementAttempt();

    createWebhookSettlementAccounts();

    $webhook = createSucceededWebhook($attempt);

    app(IncomingWebhookProcessingService::class)->process($webhook);

    $transaction = LedgerTransaction::first();

    $debit = LedgerEntry::where('ledger_transaction_id', $transaction->id)
        ->where('type', 'debit')
        ->first();

    $credit = LedgerEntry::where('ledger_transaction_id', $transaction->id)
        ->where('type', 'credit')
        ->first();

    expect($debit)
        ->not->toBeNull();

    expect($credit)
        ->not->toBeNull();

    expect($debit->amount)
        ->toBe($attempt->amount);

    expect($credit->amount)
        ->toBe($attempt->amount);

    expect($debit->currency)
        ->toBe($attempt->currency);

    expect($credit->currency)
        ->toBe($attempt->currency);
});

test('reprocessing successful webhook does not create duplicate settlement', function () {
    $attempt = createWebhookSettlementAttempt();

    createWebhookSettlementAccounts();

    $webhook = createSucceededWebhook($attempt);

    $service = app(IncomingWebhookProcessingService::class);

    $service->process($webhook);

    expect(LedgerTransaction::count())
        ->toBe(1);

    expect(LedgerEntry::count())
        ->toBe(2);

    /*
     * The webhook itself is already processed, so processing it again
     * should not create another ledger posting.
     */
    $service->process($webhook);

    expect(LedgerTransaction::count())
        ->toBe(1);

    expect(LedgerEntry::count())
        ->toBe(2);
});

test('failed webhook does not create a ledger settlement', function () {
    $attempt = createWebhookSettlementAttempt();

    createWebhookSettlementAccounts();

    $attempt->update([
        'status' => 'processing',
    ]);

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_failed_' . Str::uuid(),
        'event_type' => 'payment.failed',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'received',
        'attempt_count' => 0,
        'received_at' => now(),
    ]);

    app(IncomingWebhookProcessingService::class)->process($webhook);

    $attempt->refresh();

    expect($attempt->status)
        ->toBe('failed');

    expect(LedgerTransaction::count())
        ->toBe(0);

    expect(LedgerEntry::count())
        ->toBe(0);
});