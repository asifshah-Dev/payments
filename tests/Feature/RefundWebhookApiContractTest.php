<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RefundWebhookApiContractTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function createRefundAttempt(
        string $processor = 'stripe',
        string $attemptStatus = 'pending',
        ?string $refundStatus = null,
    ): RefundAttempt {
        $merchant = Merchant::factory()->create(['status' => 'active']);

        $intent = PaymentIntent::create([
            'merchant_id'     => $merchant->id,
            'amount'          => 10000,
            'currency'        => 'USD',
            'status'          => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash'    => hash('sha256', fake()->uuid()),
        ]);

        $refund = Refund::create([
            'payment_intent_id' => $intent->id,
            'merchant_id'       => $merchant->id,
            'amount'            => 3000,
            'currency'          => 'USD',
            'status'            => $refundStatus ?? 'pending',
            'idempotency_key'   => fake()->uuid(),
            'request_hash'      => hash('sha256', fake()->uuid()),
        ]);

        return RefundAttempt::create([
            'refund_id'              => $refund->id,
            'processor'              => $processor,
            'status'                 => $attemptStatus,
            'attempt_number'         => 1,
            'processor_reference_id' => 're_' . fake()->unique()->numerify('######'),
        ]);
    }

    private function webhookPayload(
        string $eventId,
        string $type,
        string $referenceId,
        array $extra = [],
    ): array {
        return array_replace_recursive([
            'id'   => $eventId,
            'type' => $type,
            'data' => ['object' => ['id' => $referenceId]],
        ], $extra);
    }

    /**
     * A fresh Stripe-style signature header. When the test wants to
     * simulate a missing header, pass `signature: ''`. When it wants a
     * specific (bad or old) one, pass it explicitly.
     */
    private function freshSignature(): string
    {
        return 't=' . now()->timestamp . ',v1=valid_secret_signature';
    }

    private function postWebhook(
        string $processor,
        array $payload,
        ?string $signature = null,
    ): TestResponse {
        // null means "build a fresh valid one".
        if ($signature === null) {
            $signature = $this->freshSignature();
        }

        $headers = ['Accept' => 'application/json'];

        // '' means "send no header at all".
        if ($signature !== '') {
            $headers['Stripe-Signature'] = $signature;
        }

        return $this->postJson("/api/v1/webhooks/{$processor}", $payload, $headers);
    }

    // ---------------------------------------------------------------------
    // 1–2. Happy paths
    // ---------------------------------------------------------------------

    public function test_valid_refund_success_webhook_returns_200(): void
    {
        $attempt = $this->createRefundAttempt();

        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_success',
            'refund.succeeded',
            $attempt->processor_reference_id,
        ))->assertStatus(200);

        $this->assertSame('succeeded', $attempt->fresh()->status);
    }

    public function test_valid_refund_failure_webhook_returns_200(): void
    {
        $attempt = $this->createRefundAttempt();

        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_failure',
            'refund.failed',
            $attempt->processor_reference_id,
        ))->assertStatus(200);

        $this->assertSame('failed', $attempt->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // 3. Signature
    // ---------------------------------------------------------------------

    public function test_invalid_signature_returns_401(): void
    {
        $attempt = $this->createRefundAttempt();

        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_bad_sig',
            'refund.succeeded',
            $attempt->processor_reference_id,
        ), signature: 't=' . now()->timestamp . ',v1=not-a-valid-signature')
          ->assertStatus(401);

        $this->assertSame('pending', $attempt->fresh()->status);
    }

    public function test_missing_signature_returns_401(): void
    {
        $attempt = $this->createRefundAttempt();

        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_no_sig',
            'refund.succeeded',
            $attempt->processor_reference_id,
        ), signature: '')->assertStatus(401);
    }

    // ---------------------------------------------------------------------
    // 4–5. Required fields
    // ---------------------------------------------------------------------

    public function test_missing_event_id_returns_422(): void
    {
        $payload = [
            'type' => 'refund.succeeded',
            'data' => ['object' => ['id' => 're_anything']],
        ];

        $this->postWebhook('stripe', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($msg) => str_contains($msg, 'Event ID'));
    }

    public function test_missing_event_type_returns_422(): void
    {
        $payload = [
            'id'   => 'evt_api_no_type',
            'data' => ['object' => ['id' => 're_anything']],
        ];

        $this->postWebhook('stripe', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($msg) => str_contains($msg, 'Event type'));
    }

    // ---------------------------------------------------------------------
    // 6–7. Unknown type / unknown reference are handled silently
    // ---------------------------------------------------------------------

    public function test_unknown_event_type_is_accepted_and_ignored(): void
    {
        $attempt = $this->createRefundAttempt();

        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_unknown_type',
            'charge.dispute.created',
            $attempt->processor_reference_id,
        ))->assertStatus(200);

        $this->assertSame('pending', $attempt->fresh()->status);
    }

    public function test_unknown_processor_reference_is_accepted(): void
    {
        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_unknown_ref',
            'refund.succeeded',
            're_does_not_exist',
        ))->assertStatus(200);
    }

    // ---------------------------------------------------------------------
    // 8. Duplicate webhook is idempotent
    // ---------------------------------------------------------------------

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $attempt = $this->createRefundAttempt();
        $payload = $this->webhookPayload(
            'evt_api_duplicate',
            'refund.succeeded',
            $attempt->processor_reference_id,
        );

        $this->postWebhook('stripe', $payload)->assertStatus(200);
        $this->postWebhook('stripe', $payload)->assertStatus(200);

        $this->assertSame(1, PaymentWebhookEvent::where('event_id', 'evt_api_duplicate')->count());
        $this->assertSame('succeeded', $attempt->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // 9. Processor identity comes from the route, not the payload
    // ---------------------------------------------------------------------

    public function test_stripe_webhook_cannot_modify_a_paypal_attempt(): void
    {
        $attempt = $this->createRefundAttempt('paypal');

        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_cross',
            'refund.succeeded',
            $attempt->processor_reference_id,
        ))->assertStatus(200);

        $this->assertSame('pending', $attempt->fresh()->status);
    }

    public function test_processor_in_payload_cannot_trick_the_service(): void
    {
        $attempt = $this->createRefundAttempt('stripe');

        $payload = $this->webhookPayload(
            'evt_api_spoof',
            'refund.succeeded',
            $attempt->processor_reference_id,
            ['processor' => 'stripe'],
        );

        $this->postWebhook('paypal', $payload)->assertStatus(200);

        $this->assertSame('pending', $attempt->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // 10–11. State after valid webhooks
    // ---------------------------------------------------------------------

    public function test_refund_and_attempt_state_after_success_webhook(): void
    {
        $attempt = $this->createRefundAttempt('stripe', 'pending');

        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_state_ok',
            'refund.succeeded',
            $attempt->processor_reference_id,
        ))->assertStatus(200);

        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame('succeeded', $attempt->refund->fresh()->status);
    }

    public function test_refund_and_attempt_state_after_failure_webhook(): void
    {
        $attempt = $this->createRefundAttempt('stripe', 'pending');

        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_state_fail',
            'refund.failed',
            $attempt->processor_reference_id,
            ['data' => ['object' => ['failure_message' => 'Card declined']]],
        ))->assertStatus(200);

        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertSame('Card declined', $attempt->fresh()->error_message);
        $this->assertSame('failed', $attempt->refund->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // 12. Malformed payload
    // ---------------------------------------------------------------------

    public function test_malformed_json_returns_400(): void
    {
        $response = $this->call(
            'POST',
            '/api/v1/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->freshSignature(),
            ],
            '{not valid json',
        );

        $this->assertContains($response->status(), [400, 422]);
    }

    // ---------------------------------------------------------------------
    // 13. Event is logged even when processing fails downstream
    // ---------------------------------------------------------------------

    public function test_event_is_persisted_for_unknown_reference(): void
    {
        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_logged',
            'refund.succeeded',
            're_never_existed',
        ))->assertStatus(200);

        $this->assertDatabaseHas('payment_webhook_events', [
            'event_id' => 'evt_api_logged',
        ]);
    }

    // ---------------------------------------------------------------------
    // 14. Signature must be verified against the raw body, not the parsed payload
    // ---------------------------------------------------------------------

    public function test_signature_is_verified_against_the_raw_body_not_the_parsed_payload(): void
    {
        $attempt = $this->createRefundAttempt('stripe');

        $canonical = json_encode($this->webhookPayload(
            'evt_raw_body_test',
            'refund.succeeded',
            $attempt->processor_reference_id,
        ));

        $reordered = '  ' . json_encode(array_reverse(
            $this->webhookPayload(
                'evt_raw_body_test',
                'refund.succeeded',
                $attempt->processor_reference_id,
            ),
            true,
        )) . '  ';

        $this->assertEquals(
            json_decode($canonical, true),
            json_decode($reordered, true),
            'Test setup: the two bodies must decode to equivalent arrays.',
        );

        $this->assertNotSame($canonical, $reordered);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->freshSignature(),
            ],
            $reordered,
        );

        $response->assertStatus(200);

        $this->assertSame('succeeded', $attempt->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // 15. Replay window
    // ---------------------------------------------------------------------

    public function test_webhook_older_than_the_replay_window_is_rejected(): void
    {
        $attempt = $this->createRefundAttempt('stripe');

        $oldTimestamp = now()->subHour()->timestamp;

        $payload = $this->webhookPayload(
            'evt_replay_old',
            'refund.succeeded',
            $attempt->processor_reference_id,
        );

        $this->postWebhook(
            'stripe',
            $payload,
            signature: "t={$oldTimestamp},v1=valid_secret_signature",
        )->assertStatus(401);

        $this->assertSame('pending', $attempt->fresh()->status);

        $this->assertDatabaseMissing('payment_webhook_events', [
            'event_id' => 'evt_replay_old',
        ]);
    }

    public function test_webhook_within_the_replay_window_is_accepted(): void
    {
        $attempt = $this->createRefundAttempt('stripe');

        $freshTimestamp = now()->subSeconds(10)->timestamp;

        $payload = $this->webhookPayload(
            'evt_replay_fresh',
            'refund.succeeded',
            $attempt->processor_reference_id,
        );

        $this->postWebhook(
            'stripe',
            $payload,
            signature: "t={$freshTimestamp},v1=valid_secret_signature",
        )->assertStatus(200);

        $this->assertSame('succeeded', $attempt->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // 16. A signed webhook with no reference still returns 200
    // ---------------------------------------------------------------------

    public function test_valid_signed_webhook_with_no_reference_still_returns_200(): void
    {
        $payload = [
            'id'   => 'evt_no_reference',
            'type' => 'refund.succeeded',
            'data' => ['object' => []],
        ];

        $this->postWebhook('stripe', $payload)->assertStatus(200);
    }
}