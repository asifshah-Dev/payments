<?php

use App\Models\IncomingWebhookLog;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\IncomingWebhookProcessingService;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createPaymentSuccessFlowAttempt(): PaymentAttempt
{
    $merchant = Merchant::create([
        'name' => 'Integration Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $paymentIntent = PaymentIntent::create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Successful payment',
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

function createPaymentSuccessFlowAccounts(): array
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

test('successful payment can be settled after webhook processing', function () {
    $attempt = createPaymentSuccessFlowAttempt();

    $accounts = createPaymentSuccessFlowAccounts();

    $webhook = IncomingWebhookLog::create([
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

    /*
     * Step 1:
     * Process the processor webhook.
     */
    app(IncomingWebhookProcessingService::class)
        ->process($webhook);

    /*
     * Step 2:
     * Payment attempt must now be succeeded.
     */
    $attempt->refresh();

    expect($attempt->status)
        ->toBe('succeeded');

    /*
     * Step 3:
     * Settle the successful payment.
     */
    
    $transaction = app(PaymentSettlementService::class)->settle(
    paymentAttempt: $attempt,
);

    /*
     * Step 4:
     * Verify ledger posting.
     */
    expect($transaction)
        ->toBeInstanceOf(LedgerTransaction::class);

    expect($transaction->payment_attempt_id)
        ->toBe($attempt->id);

    expect($transaction->amount)
        ->toBe(5000);

    expect($transaction->currency)
        ->toBe('USD');

    expect(LedgerEntry::count())
        ->toBe(2);

    expect(LedgerEntry::where('type', 'debit')->count())
        ->toBe(1);

    expect(LedgerEntry::where('type', 'credit')->count())
        ->toBe(1);
});

test('failed payment is not settled', function () {
    $attempt = createPaymentSuccessFlowAttempt();

    $attempt->update([
        'status' => 'failed',
    ]);

    $attempt->paymentIntent->update([
        'status' => 'failed',
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

test('settlement remains idempotent after repeated settlement request', function () {
    $attempt = createPaymentSuccessFlowAttempt();

    $attempt->update([
        'status' => 'succeeded',
    ]);

    $attempt->paymentIntent->update([
        'status' => 'succeeded',
    ]);

    $accounts = createPaymentSuccessFlowAccounts();

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