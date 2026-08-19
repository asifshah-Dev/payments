<?php

use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\PaymentAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createPaymentIntentForAttemptTest(): PaymentIntent
{
    $merchant = Merchant::factory()->create();

    return PaymentIntent::create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Test payment',
        'status' => 'pending',
        'idempotency_key' => Str::uuid()->toString(),
        'request_hash' => hash('sha256', Str::uuid()->toString()),
    ]);
}

test('creates a payment attempt for an existing payment intent', function () {
    $paymentIntent = createPaymentIntentForAttemptTest();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    expect($attempt)
        ->toBeInstanceOf(PaymentAttempt::class)
        ->and($attempt->payment_intent_id)->toBe($paymentIntent->id)
        ->and($attempt->processor)->toBe('stripe');
});

test('copies amount and currency from the payment intent', function () {
    $paymentIntent = createPaymentIntentForAttemptTest();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    expect($attempt->amount)->toBe(5000)
        ->and($attempt->currency)->toBe('USD');
});

test('starts a payment attempt with pending status', function () {
    $paymentIntent = createPaymentIntentForAttemptTest();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    expect($attempt->status)->toBe('pending');
});

test('accepts supported processors', function () {
    $paymentIntent = createPaymentIntentForAttemptTest();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    expect($attempt->processor)->toBe('stripe');
});

test('rejects an unsupported processor', function () {
    $paymentIntent = createPaymentIntentForAttemptTest();

    expect(fn () => app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'unknown_processor',
    ))->toThrow(InvalidArgumentException::class);

    expect(PaymentAttempt::count())->toBe(0);
});

test('rejects a nonexistent payment intent', function () {
    expect(fn () => app(PaymentAttemptService::class)->create(
        paymentIntentId: Str::uuid()->toString(),
        processor: 'stripe',
    ))->toThrow(RuntimeException::class);

    expect(PaymentAttempt::count())->toBe(0);
});

test('allows multiple attempts for the same payment intent', function () {
    $paymentIntent = createPaymentIntentForAttemptTest();

    $service = app(PaymentAttemptService::class);

    $first = $service->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    $second = $service->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    expect($first->id)->not->toBe($second->id)
        ->and(PaymentAttempt::count())->toBe(2);
});

test('allows a processor reference to be stored', function () {
    $paymentIntent = createPaymentIntentForAttemptTest();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
        processorReferenceId: 'pi_test_123',
    );

    expect($attempt->processor_reference_id)->toBe('pi_test_123');
});

test('allows multiple attempts without a processor reference', function () {
    $paymentIntent = createPaymentIntentForAttemptTest();

    $service = app(PaymentAttemptService::class);

    $first = $service->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    $second = $service->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    expect($first->processor_reference_id)->toBeNull()
        ->and($second->processor_reference_id)->toBeNull()
        ->and(PaymentAttempt::count())->toBe(2);
});

test('rejects duplicate processor reference for the same processor', function () {
    $paymentIntent = createPaymentIntentForAttemptTest();

    $service = app(PaymentAttemptService::class);

    $service->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
        processorReferenceId: 'pi_test_123',
    );

    expect(fn () => $service->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
        processorReferenceId: 'pi_test_123',
    ))->toThrow(\Illuminate\Database\QueryException::class);

    expect(PaymentAttempt::count())->toBe(1);
});

test('allows the same processor reference for different processors', function () {
    $paymentIntent = createPaymentIntentForAttemptTest();

    $service = app(PaymentAttemptService::class);

    $stripeAttempt = $service->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
        processorReferenceId: 'same_ref',
    );

    $paypalAttempt = $service->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'paypal',
        processorReferenceId: 'same_ref',
    );

    expect($stripeAttempt->id)->not->toBe($paypalAttempt->id)
        ->and(PaymentAttempt::count())->toBe(2);
});
test('transitions a payment attempt from pending to processing', function () {
    $paymentIntent = PaymentIntent::factory()->create();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    $updatedAttempt = app(PaymentAttemptService::class)->transition(
        attempt: $attempt,
        toStatus: 'processing',
    );

    expect($updatedAttempt->status)->toBe('processing');

    $paymentIntent->refresh();

    expect($paymentIntent->status)->toBe('processing');
});
test('transitions a payment attempt from processing to succeeded', function () {
    $paymentIntent = PaymentIntent::factory()->create([
        'status' => 'pending',
    ]);

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    app(PaymentAttemptService::class)->transition(
        attempt: $attempt,
        toStatus: 'processing',
    );

    $updatedAttempt = app(PaymentAttemptService::class)->transition(
        attempt: $attempt->fresh(),
        toStatus: 'succeeded',
    );

    expect($updatedAttempt->status)->toBe('succeeded');

    $paymentIntent->refresh();

    expect($paymentIntent->status)->toBe('succeeded');
});
test('transitions a payment attempt from processing to failed', function () {
    $paymentIntent = PaymentIntent::factory()->create([
        'status' => 'pending',
    ]);

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    app(PaymentAttemptService::class)->transition(
        attempt: $attempt,
        toStatus: 'processing',
    );

    $updatedAttempt = app(PaymentAttemptService::class)->transition(
        attempt: $attempt->fresh(),
        toStatus: 'failed',
    );

    expect($updatedAttempt->status)->toBe('failed');

    $paymentIntent->refresh();

    expect($paymentIntent->status)->toBe('failed');
});
test('rejects pending to succeeded transition', function () {
    $paymentIntent = PaymentIntent::factory()->create();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    expect(fn () => app(PaymentAttemptService::class)->transition(
        attempt: $attempt,
        toStatus: 'succeeded',
    ))->toThrow(InvalidArgumentException::class);

    expect($attempt->fresh()->status)->toBe('pending');
});
test('rejects pending to failed transition', function () {
    $paymentIntent = PaymentIntent::factory()->create();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    expect(fn () => app(PaymentAttemptService::class)->transition(
        attempt: $attempt,
        toStatus: 'failed',
    ))->toThrow(InvalidArgumentException::class);

    expect($attempt->fresh()->status)->toBe('pending');
});
test('does not allow a succeeded attempt to transition again', function () {
    $paymentIntent = PaymentIntent::factory()->create();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    app(PaymentAttemptService::class)->transition(
        attempt: $attempt,
        toStatus: 'processing',
    );

    app(PaymentAttemptService::class)->transition(
        attempt: $attempt->fresh(),
        toStatus: 'succeeded',
    );

    expect(fn () => app(PaymentAttemptService::class)->transition(
        attempt: $attempt->fresh(),
        toStatus: 'failed',
    ))->toThrow(InvalidArgumentException::class);

    expect($attempt->fresh()->status)->toBe('succeeded');
});
test('does not allow a failed attempt to transition again', function () {
    $paymentIntent = PaymentIntent::factory()->create();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    app(PaymentAttemptService::class)->transition(
        attempt: $attempt,
        toStatus: 'processing',
    );

    app(PaymentAttemptService::class)->transition(
        attempt: $attempt->fresh(),
        toStatus: 'failed',
    );

    expect(fn () => app(PaymentAttemptService::class)->transition(
        attempt: $attempt->fresh(),
        toStatus: 'processing',
    ))->toThrow(InvalidArgumentException::class);

    expect($attempt->fresh()->status)->toBe('failed');
});
test('rejects an unknown payment attempt status', function () {
    $paymentIntent = PaymentIntent::factory()->create();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    expect(fn () => app(PaymentAttemptService::class)->transition(
        attempt: $attempt,
        toStatus: 'unknown',
    ))->toThrow(InvalidArgumentException::class);

    expect($attempt->fresh()->status)->toBe('pending');
});
test('does not allow processing attempt to transition to processing again', function () {
    $paymentIntent = PaymentIntent::factory()->create();

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    app(PaymentAttemptService::class)->transition(
        attempt: $attempt,
        toStatus: 'processing',
    );

    expect(fn () => app(PaymentAttemptService::class)->transition(
        attempt: $attempt->fresh(),
        toStatus: 'processing',
    ))->toThrow(InvalidArgumentException::class);

    expect($attempt->fresh()->status)->toBe('processing');
});
test('does not change payment intent when attempt transition is invalid', function () {
    $paymentIntent = PaymentIntent::factory()->create([
        'status' => 'pending',
    ]);

    $attempt = app(PaymentAttemptService::class)->create(
        paymentIntentId: $paymentIntent->id,
        processor: 'stripe',
    );

    expect(fn () => app(PaymentAttemptService::class)->transition(
        attempt: $attempt,
        toStatus: 'succeeded',
    ))->toThrow(InvalidArgumentException::class);

    expect($attempt->fresh()->status)->toBe('pending');

    $paymentIntent->refresh();

    expect($paymentIntent->status)->toBe('pending');
});