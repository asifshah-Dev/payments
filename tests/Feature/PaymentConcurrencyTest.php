<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Models\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PaymentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_idempotency_keys_prevent_duplicate_intents(): void
    {
        $merchant = Merchant::factory()->create();
        $idempotencyKey = fake()->uuid();
        
        $payload = [
            'amount' => 5000,
            'currency' => 'USD',
        ];

        $token = 'test-token-' . $merchant->id;

        $responseFirst = $this->withToken($token)
                              ->withHeader('Idempotency-Key', $idempotencyKey)
                              ->postJson('/api/v1/payment-intents', $payload);

        $responseSecond = $this->withToken($token)
                               ->withHeader('Idempotency-Key', $idempotencyKey)
                               ->postJson('/api/v1/payment-intents', $payload);

        $responseFirst->assertSuccessful();
        $responseSecond->assertSuccessful();

        $this->assertEquals(1, PaymentIntent::where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_different_merchants_can_use_same_idempotency_key_independently(): void
    {
        $merchantA = Merchant::factory()->create();
        $merchantB = Merchant::factory()->create();
        $sharedKey = fake()->uuid();

        $payload = [
            'amount' => 3000,
            'currency' => 'USD',
        ];

        $responseA = $this->withToken('test-token-' . $merchantA->id)
                          ->withHeader('Idempotency-Key', $sharedKey)
                          ->postJson('/api/v1/payment-intents', $payload);

        $responseB = $this->withToken('test-token-' . $merchantB->id)
                          ->withHeader('Idempotency-Key', $sharedKey)
                          ->postJson('/api/v1/payment-intents', $payload);

        $responseA->assertSuccessful();
        $responseB->assertSuccessful();

        // Each merchant must maintain isolated uniqueness for their idempotency keys
        $this->assertEquals(2, PaymentIntent::where('idempotency_key', $sharedKey)->count());
    }

    public function test_duplicate_webhook_events_are_idempotent(): void
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
            'processor_reference_id' => 'pi_webhook_dup_test',
        ]);

        $payload = [
            'id' => 'evt_duplicate_check_99',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_webhook_dup_test',
                ],
            ],
        ];

        $responseFirst = $this->withHeader('Stripe-Signature', 'valid_secret_signature')
                              ->postJson('/api/v1/webhooks/stripe', $payload);

        $responseSecond = $this->withHeader('Stripe-Signature', 'valid_secret_signature')
                               ->postJson('/api/v1/webhooks/stripe', $payload);

        $responseFirst->assertStatus(Response::HTTP_OK);
        $responseSecond->assertStatus(Response::HTTP_OK);

        $this->assertEquals(1, PaymentWebhookEvent::where('event_id', 'evt_duplicate_check_99')->count());
        $this->assertEquals('succeeded', $intent->fresh()->status);
    }

    public function test_webhook_ignored_for_non_existent_processor_reference(): void
    {
        $payload = [
            'id' => 'evt_orphan_event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_non_existent_ref',
                ],
            ],
        ];

        $response = $this->withHeader('Stripe-Signature', 'valid_secret_signature')
                         ->postJson('/api/v1/webhooks/stripe', $payload);

        $response->assertStatus(Response::HTTP_OK);
        $this->assertEquals(1, PaymentWebhookEvent::where('event_id', 'evt_orphan_event')->count());
    }
}