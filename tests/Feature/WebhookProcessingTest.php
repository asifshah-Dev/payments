<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Models\PaymentWebhookEvent;
use App\Services\Payments\WebhookProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WebhookProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_valid_success_webhook_and_updates_attempt_and_intent(): void
    {
        $merchant = Merchant::factory()->create();
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'processing',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $attempt = PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'amount' => 5000,
            'currency' => 'USD',
            'processor_reference_id' => 'pi_12345',
        ]);

        $payload = [
            'data' => [
                'object' => [
                    'id' => 'pi_12345',
                ],
            ],
        ];

        $service = app(WebhookProcessorService::class);
        $service->handle('stripe', 'evt_999', 'payment_intent.succeeded', $payload, 'valid_secret_signature');

        $this->assertEquals('succeeded', $attempt->fresh()->status);
        $this->assertEquals('succeeded', $intent->fresh()->status);

        $webhookEvent = PaymentWebhookEvent::where('event_id', 'evt_999')->first();
        $this->assertEquals('processed', $webhookEvent->status);
    }

    public function test_it_rejects_webhook_with_invalid_signature(): void
    {
        $service = app(WebhookProcessorService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid webhook signature.');

        $service->handle('stripe', 'evt_100', 'payment_intent.succeeded', [], 'bad_signature');
    }

    public function test_it_is_idempotent_on_duplicate_webhook_deliveries(): void
    {
        $merchant = Merchant::factory()->create();
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'processing',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $attempt = PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'amount' => 5000,
            'currency' => 'USD',
            'processor_reference_id' => 'pi_999',
        ]);

        $payload = [
            'data' => [
                'object' => [
                    'id' => 'pi_999',
                ],
            ],
        ];

        $service = app(WebhookProcessorService::class);

        // Deliver webhook first time
        $service->handle('stripe', 'evt_dup', 'payment_intent.succeeded', $payload, 'valid_secret_signature');
        $this->assertEquals('succeeded', $attempt->fresh()->status);

        // Modify attempt status manually to test that duplicate delivery is a silent/idempotent pass
        $attempt->update(['status' => 'succeeded']);

        // Deliver webhook second time (duplicate)
        $service->handle('stripe', 'evt_dup', 'payment_intent.succeeded', $payload, 'valid_secret_signature');

        $this->assertEquals(1, PaymentWebhookEvent::where('event_id', 'evt_dup')->count());
        $this->assertEquals('processed', PaymentWebhookEvent::where('event_id', 'evt_dup')->first()->status);
    }

    public function test_it_ignores_webhook_with_unknown_processor_reference(): void
    {
        $payload = [
            'data' => [
                'object' => [
                    'id' => 'pi_nonexistent',
                ],
            ],
        ];

        $service = app(WebhookProcessorService::class);
        $service->handle('stripe', 'evt_unknown', 'payment_intent.succeeded', $payload, 'valid_secret_signature');

        $webhookEvent = PaymentWebhookEvent::where('event_id', 'evt_unknown')->first();
        $this->assertEquals('failed', $webhookEvent->status);
        $this->assertStringContainsString('payment attempt not found', $webhookEvent->error_message);
    }

    public function test_it_processes_valid_failure_webhook_and_updates_attempt_and_intent(): void
    {
        $merchant = Merchant::factory()->create();
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'processing',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $attempt = PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'amount' => 5000,
            'currency' => 'USD',
            'processor_reference_id' => 'pi_fail_123',
        ]);

        $payload = [
            'data' => [
                'object' => [
                    'id' => 'pi_fail_123',
                    'last_payment_error' => [
                        'code' => 'card_declined',
                        'message' => 'The card was declined.',
                    ],
                ],
            ],
        ];

        $service = app(WebhookProcessorService::class);
        $service->handle('stripe', 'evt_fail_999', 'payment_intent.failed', $payload, 'valid_secret_signature');

        $this->assertEquals('failed', $attempt->fresh()->status);
        $this->assertEquals('card_declined', $attempt->fresh()->failure_code);
        $this->assertEquals('failed', $intent->fresh()->status);

        $webhookEvent = PaymentWebhookEvent::where('event_id', 'evt_fail_999')->first();
        $this->assertEquals('processed', $webhookEvent->status);
    }

    public function test_it_ignores_unhandled_event_types(): void
    {
        $payload = [
            'data' => [
                'object' => [
                    'id' => 'pi_some_id',
                ],
            ],
        ];

        $service = app(WebhookProcessorService::class);
        $service->handle('stripe', 'evt_unhandled', 'charge.dispute.created', $payload, 'valid_secret_signature');

        $webhookEvent = PaymentWebhookEvent::where('event_id', 'evt_unhandled')->first();
        $this->assertEquals('ignored', $webhookEvent->status);
        $this->assertStringContainsString('Unhandled event type', $webhookEvent->error_message);
    }

    public function test_it_can_reprocess_failed_webhook_event_once_attempt_exists(): void
    {
        $payload = [
            'data' => [
                'object' => [
                    'id' => 'pi_delayed_123',
                ],
            ],
        ];

        $service = app(WebhookProcessorService::class);

        // 1. First delivery fails because attempt doesn't exist yet
        $service->handle('stripe', 'evt_retry_1', 'payment_intent.succeeded', $payload, 'valid_secret_signature');
        
        $webhookEvent = PaymentWebhookEvent::where('event_id', 'evt_retry_1')->first();
        $this->assertEquals('failed', $webhookEvent->status);

        // 2. Now create the matching payment attempt
        $merchant = Merchant::factory()->create();
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'processing',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $attempt = PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'amount' => 5000,
            'currency' => 'USD',
            'processor_reference_id' => 'pi_delayed_123',
        ]);

        // 3. Simulate re-delivery or manual reset/retry of the webhook event
        $webhookEvent->update(['status' => 'received']);
        $service->handle('stripe', 'evt_retry_1', 'payment_intent.succeeded', $payload, 'valid_secret_signature');

        $this->assertEquals('succeeded', $attempt->fresh()->status);
        $this->assertEquals('succeeded', $intent->fresh()->status);
        $this->assertEquals('processed', $webhookEvent->fresh()->status);
    }

    public function test_it_ignores_out_of_order_success_webhook_for_already_failed_attempt(): void
    {
        $merchant = Merchant::factory()->create();
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'failed',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $attempt = PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'amount' => 5000,
            'currency' => 'USD',
            'processor_reference_id' => 'pi_ooo_123',
            'failure_code' => 'card_declined',
        ]);

        $payload = [
            'data' => [
                'object' => [
                    'id' => 'pi_ooo_123',
                ],
            ],
        ];

        $service = app(WebhookProcessorService::class);
        $service->handle('stripe', 'evt_ooo_success', 'payment_intent.succeeded', $payload, 'valid_secret_signature');

        $this->assertEquals('failed', $attempt->fresh()->status);
        $this->assertEquals('failed', $intent->fresh()->status);

        $webhookEvent = PaymentWebhookEvent::where('event_id', 'evt_ooo_success')->first();
        $this->assertEquals('ignored', $webhookEvent->status);
        $this->assertStringContainsString('Out-of-order event', $webhookEvent->error_message);
    }

    public function test_it_ignores_out_of_order_failure_webhook_for_already_succeeded_attempt(): void
    {
        $merchant = Merchant::factory()->create();
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $attempt = PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'stripe',
            'status' => 'succeeded',
            'amount' => 5000,
            'currency' => 'USD',
            'processor_reference_id' => 'pi_ooo_456',
        ]);

        $payload = [
            'data' => [
                'object' => [
                    'id' => 'pi_ooo_456',
                    'last_payment_error' => [
                        'code' => 'expired_card',
                        'message' => 'Card expired.',
                    ],
                ],
            ],
        ];

        $service = app(WebhookProcessorService::class);
        $service->handle('stripe', 'evt_ooo_failure', 'payment_intent.failed', $payload, 'valid_secret_signature');

        $this->assertEquals('succeeded', $attempt->fresh()->status);
        $this->assertEquals('succeeded', $intent->fresh()->status);

        $webhookEvent = PaymentWebhookEvent::where('event_id', 'evt_ooo_failure')->first();
        $this->assertEquals('ignored', $webhookEvent->status);
        $this->assertStringContainsString('Out-of-order event', $webhookEvent->error_message);
    }
}