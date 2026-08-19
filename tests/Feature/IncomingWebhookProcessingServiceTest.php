<?php

use App\Models\IncomingWebhookLog;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\IncomingWebhookProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

uses(RefreshDatabase::class);

function createProcessingPaymentAttempt(): PaymentAttempt
{
    $merchant = \App\Models\Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $paymentIntent = PaymentIntent::create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Webhook test',
        'status' => 'pending',
        'idempotency_key' => Str::uuid()->toString(),
        'request_hash' => hash('sha256', Str::random(20)),
    ]);

    return PaymentAttempt::create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'pending',
        'amount' => $paymentIntent->amount,
        'currency' => $paymentIntent->currency,
    ]);
}
test('processes a payment processing webhook', function () {
    $attempt = createProcessingPaymentAttempt();

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_processing',
        'event_type' => 'payment.processing',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'received',
        'attempt_count' => 0,
        'received_at' => now(),
    ]);

    $processed = app(IncomingWebhookProcessingService::class)
        ->process($webhook);

    expect($attempt->fresh()->status)->toBe('processing');

    expect($processed->status)->toBe('processed')
        ->and($processed->attempt_count)->toBe(1)
        ->and($processed->processed_at)->not->toBeNull();
});
test('processes a payment succeeded webhook', function () {
    $attempt = createProcessingPaymentAttempt();

    $attempt->update([
        'status' => 'processing',
    ]);

    $attempt->paymentIntent->update([
        'status' => 'processing',
    ]);

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_succeeded',
        'event_type' => 'payment.succeeded',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'received',
        'attempt_count' => 0,
        'received_at' => now(),
    ]);

    app(IncomingWebhookProcessingService::class)
        ->process($webhook);

    expect($attempt->fresh()->status)->toBe('succeeded')
        ->and($attempt->paymentIntent->fresh()->status)->toBe('succeeded');
});
test('processes a payment failed webhook', function () {
    $attempt = createProcessingPaymentAttempt();

    $attempt->update([
        'status' => 'processing',
    ]);

    $attempt->paymentIntent->update([
        'status' => 'processing',
    ]);

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_failed',
        'event_type' => 'payment.failed',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'received',
        'attempt_count' => 0,
        'received_at' => now(),
    ]);

    app(IncomingWebhookProcessingService::class)
        ->process($webhook);

    expect($attempt->fresh()->status)->toBe('failed')
        ->and($attempt->paymentIntent->fresh()->status)->toBe('failed');
});
test('does not process an already processed webhook again', function () {
    $attempt = createProcessingPaymentAttempt();

    $attempt->update([
        'status' => 'processing',
    ]);

    $attempt->paymentIntent->update([
        'status' => 'processing',
    ]);

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_duplicate_processing',
        'event_type' => 'payment.succeeded',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'received',
        'attempt_count' => 0,
        'received_at' => now(),
    ]);

    $service = app(IncomingWebhookProcessingService::class);

    $first = $service->process($webhook);

    $second = $service->process($webhook);

    expect($first->id)->toBe($second->id);

    expect($second->status)->toBe('processed')
        ->and($second->attempt_count)->toBe(1);

    expect($attempt->fresh()->status)->toBe('succeeded');
});
test('rejects webhook without payment attempt id', function () {
    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_missing_attempt',
        'event_type' => 'payment.succeeded',
        'payload' => [],
        'status' => 'received',
        'attempt_count' => 0,
        'received_at' => now(),
    ]);

    expect(fn () =>
        app(IncomingWebhookProcessingService::class)
            ->process($webhook)
    )->toThrow(RuntimeException::class);

    expect($webhook->fresh()->status)->toBe('failed');
});
test('rejects webhook for nonexistent payment attempt', function () {
    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_missing_attempt_record',
        'event_type' => 'payment.succeeded',
        'payload' => [
            'payment_attempt_id' => Str::uuid()->toString(),
        ],
        'status' => 'received',
        'attempt_count' => 0,
        'received_at' => now(),
    ]);

    expect(fn () =>
        app(IncomingWebhookProcessingService::class)
            ->process($webhook)
    )->toThrow(RuntimeException::class);

    expect($webhook->fresh()->status)->toBe('failed');
});
test('rejects unsupported webhook event type', function () {
    $attempt = createProcessingPaymentAttempt();

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_unknown',
        'event_type' => 'customer.created',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'received',
        'attempt_count' => 0,
        'received_at' => now(),
    ]);

    expect(fn () =>
        app(IncomingWebhookProcessingService::class)
            ->process($webhook)
    )->toThrow(InvalidArgumentException::class);

    expect($attempt->fresh()->status)->toBe('pending');
});
test('does not change payment attempt when webhook causes invalid transition', function () {
    $attempt = createProcessingPaymentAttempt();

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_invalid_transition',
        'event_type' => 'payment.succeeded',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'received',
        'attempt_count' => 0,
        'received_at' => now(),
    ]);

    expect(fn () =>
        app(IncomingWebhookProcessingService::class)
            ->process($webhook)
    )->toThrow(InvalidArgumentException::class);

    expect($attempt->fresh()->status)->toBe('pending')
        ->and($attempt->paymentIntent->fresh()->status)->toBe('pending');
});
test('retries a failed webhook', function () {
    $attempt = createProcessingPaymentAttempt();

    $attempt->update([
        'status' => 'processing',
    ]);

    $attempt->paymentIntent->update([
        'status' => 'processing',
    ]);

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_retry_' . Str::uuid(),
        'event_type' => 'payment.succeeded',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'failed',
        'attempt_count' => 1,
    ]);

    $processed = app(IncomingWebhookProcessingService::class)
        ->process($webhook);

    expect($processed->status)->toBe('processed')
        ->and($processed->attempt_count)->toBe(2)
        ->and($processed->locked_at)->toBeNull()
        ->and($processed->locked_by)->toBeNull();
});
test('rejects webhook after maximum processing attempts', function () {
    $attempt = createProcessingPaymentAttempt();

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_max_' . Str::uuid(),
        'event_type' => 'payment.succeeded',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'failed',
        'attempt_count' => 5,
    ]);

    expect(fn () =>
        app(IncomingWebhookProcessingService::class)
            ->process($webhook)
    )->toThrow(RuntimeException::class);

    expect($webhook->fresh()->status)->toBe('failed')
        ->and($webhook->fresh()->attempt_count)->toBe(5);
});
test('rejects a webhook that is currently locked', function () {
    $attempt = createProcessingPaymentAttempt();

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_locked_' . Str::uuid(),
        'event_type' => 'payment.succeeded',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'received',
        'attempt_count' => 0,
        'locked_at' => now(),
        'locked_by' => 'worker-1',
    ]);

    expect(fn () =>
        app(IncomingWebhookProcessingService::class)
            ->process($webhook)
    )->toThrow(RuntimeException::class);

    expect($webhook->fresh()->status)->toBe('received')
        ->and($webhook->fresh()->attempt_count)->toBe(0);
});
test('reclaims an expired webhook lock', function () {
    $attempt = createProcessingPaymentAttempt();
  //  $attempt = createProcessingPaymentAttempt();

    $attempt->update([
        'status' => 'processing',
    ]);

    $attempt->paymentIntent->update([
        'status' => 'processing',
    ]);

    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_expired_' . Str::uuid(),
        'event_type' => 'payment.succeeded',
        'payload' => [
            'payment_attempt_id' => $attempt->id,
        ],
        'status' => 'received',
        'attempt_count' => 1,
        'locked_at' => now()->subMinutes(10),
        'locked_by' => 'dead-worker',
    ]);

    $processed = app(IncomingWebhookProcessingService::class)
        ->process($webhook);

    expect($processed->status)->toBe('processed')
        ->and($processed->attempt_count)->toBe(2)
        ->and($processed->locked_at)->toBeNull()
        ->and($processed->locked_by)->toBeNull();
});
test('releases the lock when webhook processing fails', function () {
    $webhook = IncomingWebhookLog::create([
        'processor' => 'stripe',
        'processor_event_id' => 'evt_error_' . Str::uuid(),
        'event_type' => 'payment.succeeded',
        'payload' => [],
        'status' => 'received',
        'attempt_count' => 0,
    ]);

    expect(fn () =>
        app(IncomingWebhookProcessingService::class)
            ->process($webhook)
    )->toThrow(RuntimeException::class);

    $webhook->refresh();

    expect($webhook->status)->toBe('failed')
        ->and($webhook->attempt_count)->toBe(1)
        ->and($webhook->locked_at)->toBeNull()
        ->and($webhook->locked_by)->toBeNull();
});