<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\Payments\Processors\StripeProcessorAdapter;
use App\Services\Payments\Processors\PayPalProcessorAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentProcessorAdaptersTest extends TestCase
{
    use RefreshDatabase;

    public function test_stripe_adapter_returns_success_result(): void
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
        ]);

        $adapter = new StripeProcessorAdapter();
        $result = $adapter->charge($attempt);

        $this->assertTrue($result->successful);
        $this->assertNotNull($result->processorReferenceId);
        $this->assertStringStartsWith('pi_', $result->processorReferenceId);
    }

    public function test_stripe_adapter_returns_failure_result_for_declined_amount(): void
    {
        $merchant = Merchant::factory()->create();
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 99999,
            'currency' => 'USD',
            'status' => 'processing',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $attempt = PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'amount' => 99999,
            'currency' => 'USD',
        ]);

        $adapter = new StripeProcessorAdapter();
        $result = $adapter->charge($attempt);

        $this->assertFalse($result->successful);
        $this->assertEquals('card_declined', $result->failureCode);
    }

    public function test_paypal_adapter_returns_success_result(): void
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
            'processor' => 'paypal',
            'status' => 'processing',
            'amount' => 5000,
            'currency' => 'USD',
        ]);

        $adapter = new PayPalProcessorAdapter();
        $result = $adapter->charge($attempt);

        $this->assertTrue($result->successful);
        $this->assertNotNull($result->processorReferenceId);
        $this->assertStringStartsWith('PAYID-', $result->processorReferenceId);
    }

    public function test_processing_service_throws_exception_for_unsupported_processor(): void
    {
        $merchant = Merchant::factory()->create();
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported processor: [unsupported_gateway]');

        $service = app(\App\Services\Payments\PaymentProcessingService::class);
        $service->process($intent, 'unsupported_gateway');
    }

    public function test_processing_service_handles_adapter_exception_safely(): void
    {
        $merchant = Merchant::factory()->create();
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        // Temporarily bind a failing mock adapter for 'stripe'
        $this->app->bind(StripeProcessorAdapter::class, function () {
            return new class extends StripeProcessorAdapter {
                public function charge(PaymentAttempt $attempt): \App\Services\Payments\Processors\ProcessorResult
                {
                    throw new \RuntimeException('Gateway connection timed out.');
                }
            };
        });

        $service = app(\App\Services\Payments\PaymentProcessingService::class);
        $attempt = $service->process($intent, 'stripe');

        $this->assertEquals('failed', $attempt->status);
        $this->assertEquals('exception', $attempt->failure_code);
        $this->assertEquals('Gateway connection timed out.', $attempt->failure_message);
        $this->assertEquals('failed', $intent->fresh()->status);
    }
    public function test_processing_service_processes_paypal_successfully(): void
    {
        $merchant = Merchant::factory()->create();
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 7500,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $service = app(\App\Services\Payments\PaymentProcessingService::class);
        $attempt = $service->process($intent, 'paypal');

        $this->assertEquals('succeeded', $attempt->status);
        $this->assertEquals('paypal', $attempt->processor);
        $this->assertNotNull($attempt->processor_reference_id);
        $this->assertStringStartsWith('PAYID-', $attempt->processor_reference_id);
        $this->assertEquals('succeeded', $intent->fresh()->status);
    }
}