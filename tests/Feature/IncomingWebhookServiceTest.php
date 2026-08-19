<?php

use App\Models\IncomingWebhookLog;
use App\Services\IncomingWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);
test('stores an incoming webhook', function () {
    $log = app(IncomingWebhookService::class)->receive(
        processor: 'stripe',
        processorEventId: 'evt_123',
        eventType: 'payment.succeeded',
        payload: [
            'id' => 'evt_123',
            'type' => 'payment.succeeded',
        ],
    );

    expect($log)->toBeInstanceOf(IncomingWebhookLog::class)
        ->and($log->processor)->toBe('stripe')
        ->and($log->processor_event_id)->toBe('evt_123')
        ->and($log->event_type)->toBe('payment.succeeded')
        ->and($log->status)->toBe('received')
        ->and($log->attempt_count)->toBe(0);

    expect(IncomingWebhookLog::count())->toBe(1);
});
test('stores the webhook payload as json data', function () {
    $payload = [
        'id' => 'evt_456',
        'type' => 'payment.failed',
        'data' => [
            'amount' => 5000,
            'currency' => 'USD',
        ],
    ];

    $log = app(IncomingWebhookService::class)->receive(
        processor: 'stripe',
        processorEventId: 'evt_456',
        eventType: 'payment.failed',
        payload: $payload,
    );

    expect($log->payload)->toBe($payload);
});
test('does not create a duplicate webhook for the same processor event', function () {
    $service = app(IncomingWebhookService::class);

    $first = $service->receive(
        processor: 'stripe',
        processorEventId: 'evt_duplicate',
        eventType: 'payment.succeeded',
        payload: [
            'id' => 'evt_duplicate',
        ],
    );

    $second = $service->receive(
        processor: 'stripe',
        processorEventId: 'evt_duplicate',
        eventType: 'payment.succeeded',
        payload: [
            'id' => 'evt_duplicate',
        ],
    );

    expect($second->id)->toBe($first->id);

    expect(IncomingWebhookLog::count())->toBe(1);
});
test('allows the same event id from different processors', function () {
    $service = app(IncomingWebhookService::class);

    $stripe = $service->receive(
        processor: 'stripe',
        processorEventId: 'evt_same',
        eventType: 'payment.succeeded',
        payload: ['id' => 'evt_same'],
    );

    $paypal = $service->receive(
        processor: 'paypal',
        processorEventId: 'evt_same',
        eventType: 'payment.succeeded',
        payload: ['id' => 'evt_same'],
    );

    expect($stripe->id)->not->toBe($paypal->id);

    expect(IncomingWebhookLog::count())->toBe(2);
});
test('new webhook starts with received status', function () {
    $log = app(IncomingWebhookService::class)->receive(
        processor: 'paypal',
        processorEventId: 'evt_status',
        eventType: 'payment.processing',
        payload: ['id' => 'evt_status'],
    );

    expect($log->status)->toBe('received')
        ->and($log->attempt_count)->toBe(0)
        ->and($log->processed_at)->toBeNull()
        ->and($log->error_message)->toBeNull();
});