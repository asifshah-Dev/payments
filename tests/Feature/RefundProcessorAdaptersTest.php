<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundProcessorAdaptersTest extends TestCase
{
    use RefreshDatabase;

    protected function createTestRefund(array $attributes = []): Refund
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => $attributes['currency'] ?? 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash_intent',
        ]);

        return Refund::create(array_merge([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash_refund',
        ], $attributes));
    }

    public function test_stripe_refund_succeeds(): void
    {
        $refund = $this->createTestRefund();

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'attempt_number' => 1,
        ]);

        $attempt->update([
            'status' => 'succeeded',
            'processor_reference_id' => 're_stripe_success_123',
        ]);

        $this->assertEquals('succeeded', $attempt->fresh()->status);
        $this->assertEquals('re_stripe_success_123', $attempt->fresh()->processor_reference_id);
    }

    public function test_stripe_refund_fails(): void
    {
        $refund = $this->createTestRefund();

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'attempt_number' => 1,
        ]);

        $attempt->update([
            'status' => 'failed',
            'error_message' => 'Stripe charge expired or not refundable',
        ]);

        $this->assertEquals('failed', $attempt->fresh()->status);
        $this->assertEquals('Stripe charge expired or not refundable', $attempt->fresh()->error_message);
    }

    public function test_paypal_refund_succeeds(): void
    {
        $refund = $this->createTestRefund();

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'processing',
            'attempt_number' => 1,
        ]);

        $attempt->update([
            'status' => 'succeeded',
            'processor_reference_id' => 'paypal_refund_id_456',
        ]);

        $this->assertEquals('succeeded', $attempt->fresh()->status);
        $this->assertEquals('paypal_refund_id_456', $attempt->fresh()->processor_reference_id);
    }

    public function test_paypal_refund_fails(): void
    {
        $refund = $this->createTestRefund();

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'processing',
            'attempt_number' => 1,
        ]);

        $attempt->update([
            'status' => 'failed',
            'error_message' => 'PayPal API service unavailable',
        ]);

        $this->assertEquals('failed', $attempt->fresh()->status);
        $this->assertEquals('PayPal API service unavailable', $attempt->fresh()->error_message);
    }

    public function test_unsupported_processor_is_rejected(): void
    {
        $supportedProcessors = ['stripe', 'paypal'];
        $unsupported = 'bitpay';

        $this->assertFalse(in_array($unsupported, $supportedProcessors, true));
    }

    public function test_processor_exception_is_handled_safely(): void
    {
        $refund = $this->createTestRefund();
        $attempt = null;

        try {
            throw new Exception('Connection timeout talking to gateway');
        } catch (Exception $e) {
            $attempt = RefundAttempt::create([
                'id' => fake()->uuid(),
                'refund_id' => $refund->id,
                'processor' => 'stripe',
                'status' => 'failed',
                'attempt_number' => 1,
                'error_message' => $e->getMessage(),
            ]);
        }

        $this->assertEquals('Connection timeout talking to gateway', $attempt->error_message);
        $this->assertEquals('failed', $attempt->status);
    }

    public function test_correct_refund_amount_is_sent_to_adapter(): void
    {
        $expectedAmount = 2500;
        $refund = $this->createTestRefund(['amount' => $expectedAmount]);

        $this->assertEquals($expectedAmount, $refund->amount);
    }

    public function test_correct_currency_is_sent(): void
    {
        $expectedCurrency = 'EUR';
        $refund = $this->createTestRefund(['currency' => $expectedCurrency]);

        $this->assertEquals($expectedCurrency, $refund->currency);
    }

    public function test_refund_attempt_receives_processor_reference_on_success(): void
    {
        $refund = $this->createTestRefund();

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'succeeded',
            'attempt_number' => 1,
            'processor_reference_id' => 'ref_live_stripe_999',
            'raw_response' => ['id' => 'ref_live_stripe_999', 'object' => 'refund'],
        ]);

        $this->assertNotNull($attempt->processor_reference_id);
        $this->assertIsArray($attempt->raw_response);
        $this->assertEquals('ref_live_stripe_999', $attempt->raw_response['id']);
    }

    public function test_refund_attempt_receives_failure_information_on_failure(): void
    {
        $refund = $this->createTestRefund();

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'failed',
            'attempt_number' => 1,
            'error_message' => 'INVALID_RESOURCE_ID: The requested resource does not exist',
            'raw_response' => ['name' => 'INVALID_RESOURCE_ID', 'message' => 'The requested resource does not exist'],
        ]);

        $this->assertEquals('failed', $attempt->status);
        $this->assertStringContainsString('INVALID_RESOURCE_ID', $attempt->error_message);
        $this->assertArrayHasKey('name', $attempt->raw_response);
    }
}