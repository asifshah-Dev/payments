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
     * Build a real Stripe-style HMAC signature for a body.
     *
     * Uses the same secret source as WebhookProcessorService::verifySignature:
     * config("webhooks.secrets.{processor}"). No fallback, so if the config
     * is missing, the test fails loudly instead of silently producing a
     * signature the service cannot verify.
     */
    private function signBody(string $processor, string $body, ?int $timestamp = null): string
    {
        $secret    = config("webhooks.secrets.{$processor}");
        $timestamp = $timestamp ?? now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Send a webhook. If $signature is null, build a valid HMAC for the body.
     * Pass '' to send no signature header at all.
     */
   /**
 * Send a webhook. If $signature is null, build a valid HMAC for the body.
 * Pass '' to send no signature header at all.
 */
private function postWebhook(
    string $processor,
    array $payload,
    ?string $signature = null,
): TestResponse {
    $body = json_encode($payload);

    if ($signature === null) {
        $signature = $this->signBody($processor, $body);
    }

    $headers = ['Accept' => 'application/json'];

    // Use Symfony-style server key. Laravel's TestCase::call() expects
    // headers as HTTP_* keys, not raw header names.
    if ($signature !== '') {
        $headers['HTTP_STRIPE_SIGNATURE'] = $signature;
    }

    return $this->call('POST', "/api/v1/webhooks/{$processor}", [], [], [], array_merge(
        $headers,
        ['CONTENT_TYPE' => 'application/json'],
    ), $body);
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

        // A well-formed header with a hex signature that will not match.
        $this->postWebhook('stripe', $this->webhookPayload(
            'evt_api_bad_sig',
            'refund.succeeded',
            $attempt->processor_reference_id,
        ), signature: 't=' . now()->timestamp . ',v1=' . str_repeat('0', 64))
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
    // 3b. HMAC-specific tests
    // ---------------------------------------------------------------------

    public function test_valid_hmac_signature_is_accepted(): void
    {
        $attempt = $this->createRefundAttempt('stripe');

        $payload = $this->webhookPayload(
            'evt_hmac_valid',
            'refund.succeeded',
            $attempt->processor_reference_id,
        );

        $this->postWebhook('stripe', $payload)->assertStatus(200);

        $this->assertSame('succeeded', $attempt->fresh()->status);
    }

    public function test_wrong_hmac_secret_is_rejected(): void
    {
        $attempt = $this->createRefundAttempt('stripe');

        $payload = $this->webhookPayload(
            'evt_hmac_wrong_secret',
            'refund.succeeded',
            $attempt->processor_reference_id,
        );
        $body      = json_encode($payload);
        $timestamp = now()->timestamp;

        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'wrong_secret');
        $header    = "t={$timestamp},v1={$signature}";

        $this->postWebhook('stripe', $payload, signature: $header)
            ->assertStatus(401);

        $this->assertSame('pending', $attempt->fresh()->status);
    }

    public function test_tampered_body_fails_hmac_verification(): void
    {
        $attempt = $this->createRefundAttempt('stripe');

        // Sign one body, send a different one.
        $originalBody = json_encode($this->webhookPayload(
            'evt_hmac_tampered',
            'refund.succeeded',
            $attempt->processor_reference_id,
        ));

        $tamperedBody = json_encode($this->webhookPayload(
            'evt_hmac_tampered',
            'refund.succeeded',
            $attempt->processor_reference_id,
            ['amount' => 99999],
        ));

        $timestamp = now()->timestamp;
        $secret    = config('webhooks.secrets.stripe');
        $signature = hash_hmac('sha256', "{$timestamp}.{$originalBody}", $secret);
        $header    = "t={$timestamp},v1={$signature}";

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $tamperedBody)->assertStatus(401);
    }

    public function test_tampered_timestamp_fails_hmac_verification(): void
    {
        $attempt = $this->createRefundAttempt('stripe');

        $body = json_encode($this->webhookPayload(
            'evt_hmac_ts_tamper',
            'refund.succeeded',
            $attempt->processor_reference_id,
        ));

        $signedAt = now()->timestamp;
        $sentAt   = $signedAt + 1;

        $secret    = config('webhooks.secrets.stripe');
        $signature = hash_hmac('sha256', "{$signedAt}.{$body}", $secret);
        $header    = "t={$sentAt},v1={$signature}";

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $body)->assertStatus(401);
    }

    public function test_missing_processor_secret_is_rejected(): void
    {
        $body = json_encode([
            'id'   => 'evt_no_secret',
            'type' => 'refund.succeeded',
            'data' => ['object' => ['id' => 're_anything']],
        ]);

        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'anything');
        $header    = "t={$timestamp},v1={$signature}";

        $this->call('POST', '/api/v1/webhooks/nonexistent_processor', [], [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $header,
        ], $body)->assertStatus(401);
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
    // 6–7. Unknown type / unknown reference
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
    // 9. Processor identity comes from the route
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
        $body      = '{not valid json';
        $timestamp = now()->timestamp;
        $secret    = config('webhooks.secrets.stripe');
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $body,
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
    // 14. Signature verified against raw body
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

        $timestamp = now()->timestamp;
        $secret    = config('webhooks.secrets.stripe');
        $signature = hash_hmac('sha256', "{$timestamp}.{$reordered}", $secret);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
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
        $payload      = $this->webhookPayload(
            'evt_replay_old',
            'refund.succeeded',
            $attempt->processor_reference_id,
        );
        $body      = json_encode($payload);
        $secret    = config('webhooks.secrets.stripe');
        $signature = hash_hmac('sha256', "{$oldTimestamp}.{$body}", $secret);

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$oldTimestamp},v1={$signature}",
        ], $body)->assertStatus(401);

        $this->assertSame('pending', $attempt->fresh()->status);
        $this->assertDatabaseMissing('payment_webhook_events', ['event_id' => 'evt_replay_old']);
    }

    public function test_webhook_within_the_replay_window_is_accepted(): void
    {
        $attempt = $this->createRefundAttempt('stripe');

        $freshTimestamp = now()->subSeconds(10)->timestamp;
        $payload        = $this->webhookPayload(
            'evt_replay_fresh',
            'refund.succeeded',
            $attempt->processor_reference_id,
        );
        $body      = json_encode($payload);
        $secret    = config('webhooks.secrets.stripe');
        $signature = hash_hmac('sha256', "{$freshTimestamp}.{$body}", $secret);

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$freshTimestamp},v1={$signature}",
        ], $body)->assertStatus(200);

        $this->assertSame('succeeded', $attempt->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // 16. Signed webhook with no reference still 200
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