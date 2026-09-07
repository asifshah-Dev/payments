<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Models\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class WebhookApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_valid_success_webhook_via_api(): void
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

        PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'amount' => 5000,
            'currency' => 'USD',
            'processor_reference_id' => 'pi_api_success',
        ]);

        $payload = [
            'id' => 'evt_api_success_1',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_api_success',
                ],
            ],
        ];

        $response = $this->withHeader('Stripe-Signature', 'valid_secret_signature')
            ->postJson('/api/v1/webhooks/stripe', $payload);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJson(['status' => 'success']);

        $this->assertEquals('succeeded', $intent->fresh()->status);
    }

    public function test_it_rejects_webhook_with_invalid_signature(): void
    {
        $payload = [
            'id' => 'evt_bad_sig',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123']]
        ];

        $response = $this->withHeader('Stripe-Signature', 'invalid_signature')
            ->postJson('/api/v1/webhooks/stripe', $payload);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED)
            ->assertJsonStructure(['error']);
    }

    public function test_it_returns_unprocessable_entity_when_event_id_is_missing(): void
    {
        $payload = [
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123']]
        ];

        $response = $this->withHeader('Stripe-Signature', 'valid_secret_signature')
            ->postJson('/api/v1/webhooks/stripe', $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_it_returns_unprocessable_entity_when_event_type_is_missing(): void
    {
        $payload = [
            'id' => 'evt_missing_type',
            'data' => ['object' => ['id' => 'pi_123']]
        ];

        $response = $this->withHeader('Stripe-Signature', 'valid_secret_signature')
            ->postJson('/api/v1/webhooks/stripe', $payload);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_it_is_idempotent_on_duplicate_api_deliveries(): void
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

        PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'amount' => 5000,
            'currency' => 'USD',
            'processor_reference_id' => 'pi_api_dup',
        ]);

        $payload = [
            'id' => 'evt_api_dup_1',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_api_dup']]
        ];

        // First call
        $this->withHeader('Stripe-Signature', 'valid_secret_signature')
            ->postJson('/api/v1/webhooks/stripe', $payload)
            ->assertStatus(Response::HTTP_OK);

        // Second call (Duplicate)
        $this->withHeader('Stripe-Signature', 'valid_secret_signature')
            ->postJson('/api/v1/webhooks/stripe', $payload)
            ->assertStatus(Response::HTTP_OK);

        $this->assertEquals(1, PaymentWebhookEvent::where('event_id', 'evt_api_dup_1')->count());
    }

    public function test_it_accepts_and_ignores_unknown_event_types(): void
    {
        $payload = [
            'id' => 'evt_unhandled_api',
            'type' => 'charge.dispute.created',
            'data' => ['object' => ['id' => 'pi_123']]
        ];

        $response = $this->withHeader('Stripe-Signature', 'valid_secret_signature')
            ->postJson('/api/v1/webhooks/stripe', $payload);

        $response->assertStatus(Response::HTTP_OK);

        $webhookEvent = PaymentWebhookEvent::where('event_id', 'evt_unhandled_api')->first();
        $this->assertEquals('ignored', $webhookEvent->status);
    }

    public function test_it_accepts_and_fails_safely_on_unknown_payment_reference(): void
    {
        $payload = [
            'id' => 'evt_unknown_ref_api',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_nonexistent_ref']]
        ];

        $response = $this->withHeader('Stripe-Signature', 'valid_secret_signature')
            ->postJson('/api/v1/webhooks/stripe', $payload);

        $response->assertStatus(Response::HTTP_OK);

        $webhookEvent = PaymentWebhookEvent::where('event_id', 'evt_unknown_ref_api')->first();
        $this->assertEquals('failed', $webhookEvent->status);
        $this->assertStringContainsString('payment attempt not found', $webhookEvent->error_message);
    }

    public function test_it_isolates_stripe_processor_from_paypal_attempts(): void
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

        PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'paypal',
            'status' => 'processing',
            'amount' => 5000,
            'currency' => 'USD',
            'processor_reference_id' => 'pi_shared_id',
        ]);

        $payload = [
            'id' => 'evt_stripe_isolation',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_shared_id']]
        ];

        $this->withHeader('Stripe-Signature', 'valid_secret_signature')
            ->postJson('/api/v1/webhooks/stripe', $payload)
            ->assertStatus(Response::HTTP_OK);

        $webhookEvent = PaymentWebhookEvent::where('event_id', 'evt_stripe_isolation')->first();
        $this->assertEquals('failed', $webhookEvent->status);
        $this->assertStringContainsString('payment attempt not found', $webhookEvent->error_message);
    }

    public function test_it_processes_valid_failure_webhook_via_api(): void
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
            'processor_reference_id' => 'pi_api_fail',
        ]);

        $payload = [
            'id' => 'evt_api_fail_1',
            'type' => 'payment_intent.failed',
            'data' => [
                'object' => [
                    'id' => 'pi_api_fail',
                    'last_payment_error' => [
                        'code' => 'insufficient_funds',
                        'message' => 'The account has insufficient funds.',
                    ],
                ],
            ],
        ];

        $response = $this->withHeader('Stripe-Signature', 'valid_secret_signature')
            ->postJson('/api/v1/webhooks/stripe', $payload);

        $response->assertStatus(Response::HTTP_OK)
            ->assertJson(['status' => 'success']);

        $this->assertEquals('failed', $attempt->fresh()->status);
        $this->assertEquals('insufficient_funds', $attempt->fresh()->failure_code);
        $this->assertEquals('failed', $intent->fresh()->status);
    }
}