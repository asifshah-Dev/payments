<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundWebhookProcessingTest extends TestCase
{
    use RefreshDatabase;

    private function createRefundAttempt(string $processor = 'stripe', string $status = 'pending'): RefundAttempt
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => hash('sha256', fake()->uuid()),
        ]);
        $refund = Refund::create([
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 3000,
            'currency' => 'USD',
            'status' => $status === 'succeeded' ? 'succeeded' : ($status === 'failed' ? 'failed' : 'pending'),
            'idempotency_key' => fake()->uuid(),
            'request_hash' => hash('sha256', fake()->uuid()),
        ]);

        return RefundAttempt::create([
            'refund_id' => $refund->id,
            'processor' => $processor,
            'status' => $status,
            'attempt_number' => 1,
            'processor_reference_id' => 're_' . fake()->unique()->numerify('######'),
        ]);
    }

    private function sendWebhook(string $processor, array $payload, ?string $signature = null)
{
    $body = json_encode($payload);

    if ($signature === null) {
        $secret    = config("webhooks.secrets.{$processor}", 'whsec_test_secret_do_not_use_in_prod');
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
        $signature = "t={$timestamp},v1={$signature}";
    }

    return $this->call('POST', "/api/v1/webhooks/{$processor}", [], [], [], [
        'CONTENT_TYPE'          => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $body);
}

    private function payload(string $eventId, string $type, string $referenceId, array $extra = []): array
    {
        return array_replace_recursive([
            'id' => $eventId,
            'type' => $type,
            'data' => ['object' => ['id' => $referenceId]],
        ], $extra);
    }

    public function test_valid_refund_success_webhook_updates_attempt_and_refund(): void
    {
        $attempt = $this->createRefundAttempt();
        $refund = $attempt->refund;

        $this->sendWebhook('stripe', $this->payload('evt_success', 'refund.succeeded', $attempt->processor_reference_id))
            ->assertOk();

        $this->assertEquals('succeeded', $attempt->fresh()->status);
        $this->assertEquals('succeeded', $refund->fresh()->status);
    }

    public function test_valid_refund_failure_webhook_updates_attempt_and_refund(): void
    {
        $attempt = $this->createRefundAttempt();
        $refund = $attempt->refund;

        $this->sendWebhook('stripe', $this->payload('evt_failure', 'refund.failed', $attempt->processor_reference_id, [
            'data' => ['object' => ['failure_message' => 'Refund rejected']],
        ]))->assertOk();

        $this->assertEquals('failed', $attempt->fresh()->status);
        $this->assertEquals('Refund rejected', $attempt->fresh()->error_message);
        $this->assertEquals('failed', $refund->fresh()->status);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->sendWebhook('stripe', $this->payload('evt_bad_signature', 'refund.succeeded', 're_missing'), 'bad-signature')
            ->assertUnauthorized();
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        $attempt = $this->createRefundAttempt();
        $payload = $this->payload('evt_duplicate', 'refund.succeeded', $attempt->processor_reference_id);

        $this->sendWebhook('stripe', $payload)->assertOk();
        $this->sendWebhook('stripe', $payload)->assertOk();

        $this->assertEquals(1, PaymentWebhookEvent::where('event_id', 'evt_duplicate')->count());
        $this->assertEquals('succeeded', $attempt->fresh()->status);
    }

    public function test_unknown_processor_reference_is_safely_ignored(): void
    {
        $this->sendWebhook('stripe', $this->payload('evt_unknown', 'refund.succeeded', 're_unknown'))->assertOk();

        $event = PaymentWebhookEvent::where('event_id', 'evt_unknown')->first();
        $this->assertEquals('failed', $event->status);
        $this->assertStringContainsString('refund attempt not found', $event->error_message);
    }

    public function test_unknown_event_type_is_safely_ignored(): void
    {
        $attempt = $this->createRefundAttempt();

        $this->sendWebhook('stripe', $this->payload('evt_unknown_type', 'charge.dispute.created', $attempt->processor_reference_id))
            ->assertOk();

        $this->assertEquals('ignored', PaymentWebhookEvent::where('event_id', 'evt_unknown_type')->value('status'));
        $this->assertEquals('pending', $attempt->fresh()->status);
    }

    public function test_processor_cannot_modify_another_processors_attempt(): void
    {
        $attempt = $this->createRefundAttempt('paypal');

        $this->sendWebhook('stripe', $this->payload('evt_cross_processor', 'refund.succeeded', $attempt->processor_reference_id))
            ->assertOk();

        $this->assertEquals('pending', $attempt->fresh()->status);
        $this->assertEquals('failed', PaymentWebhookEvent::where('event_id', 'evt_cross_processor')->value('status'));
    }

    public function test_out_of_order_success_cannot_resurrect_failed_refund(): void
    {
        $attempt = $this->createRefundAttempt('stripe', 'failed');

        $this->sendWebhook('stripe', $this->payload('evt_late_success', 'refund.succeeded', $attempt->processor_reference_id))
            ->assertOk();

        $this->assertEquals('failed', $attempt->fresh()->status);
        $this->assertEquals('ignored', PaymentWebhookEvent::where('event_id', 'evt_late_success')->value('status'));
    }

    public function test_out_of_order_failure_cannot_overwrite_succeeded_refund(): void
    {
        $attempt = $this->createRefundAttempt('stripe', 'succeeded');

        $this->sendWebhook('stripe', $this->payload('evt_late_failure', 'refund.failed', $attempt->processor_reference_id))
            ->assertOk();

        $this->assertEquals('succeeded', $attempt->fresh()->status);
        $this->assertEquals('ignored', PaymentWebhookEvent::where('event_id', 'evt_late_failure')->value('status'));
    }
}
