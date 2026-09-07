<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\PaymentAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentProcessingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_successfully_processes_a_payment_attempt_and_updates_intent(): void
    {
        $merchant = Merchant::factory()->create();
        
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'dummyhash',
        ]);

        $service = app(PaymentAttemptService::class);

        $attempt = $service->create($intent->id, 'mock');

        $this->assertEquals('pending', $attempt->status);
        $this->assertEquals('pending', $intent->fresh()->status);

        $processedAttempt = $service->process($attempt);

        $this->assertEquals('succeeded', $processedAttempt->status);
        $this->assertNotNull($processedAttempt->processor_reference_id);
        $this->assertEquals('succeeded', $intent->fresh()->status);
    }

    public function test_it_handles_payment_failure_from_processor_and_updates_intent(): void
    {
        $merchant = Merchant::factory()->create();
        
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 2500,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'dummyhash',
        ]);

        $service = app(PaymentAttemptService::class);

        $attempt = $service->create($intent->id, 'mock_fail');

        $processedAttempt = $service->process($attempt);

        $this->assertEquals('failed', $processedAttempt->status);
        $this->assertEquals('card_declined', $processedAttempt->failure_code);
        $this->assertEquals('failed', $intent->fresh()->status);
    }

    public function test_it_prevents_processing_an_already_succeeded_attempt(): void
    {
        $merchant = Merchant::factory()->create();
        
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 1000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'dummyhash',
        ]);

        $attempt = PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'mock',
            'status' => 'succeeded',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $service = app(PaymentAttemptService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->process($attempt);
    }

    public function test_it_supports_multiple_attempts_for_a_single_intent(): void
    {
        $merchant = Merchant::factory()->create();
        
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'dummyhash',
        ]);

        $service = app(PaymentAttemptService::class);

        // First attempt fails (e.g. Stripe card decline)
        $attemptOne = $service->create($intent->id, 'mock_fail');
        $processedOne = $service->process($attemptOne);
        
        $this->assertEquals('failed', $processedOne->status);
        $this->assertEquals('failed', $intent->fresh()->status);

        // Second attempt succeeds (e.g. PayPal fallback or retry)
        $attemptTwo = $service->create($intent->id, 'mock');
        $processedTwo = $service->process($attemptTwo);

        $this->assertEquals('succeeded', $processedTwo->status);
        $this->assertEquals('succeeded', $intent->fresh()->status);
        
        $this->assertCount(2, $intent->paymentAttempts ?? $intent->paymentAttempts()->get());
    }
    public function test_it_handles_unexpected_processor_exceptions_gracefully(): void
    {
        $merchant = Merchant::factory()->create();
        
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 1500,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'dummyhash',
        ]);

        $attempt = PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => 'mock',
            'status' => 'pending',
            'amount' => 1500,
            'currency' => 'USD',
        ]);

        // 1. Mock the resolver before resolving the service
        $this->mock(\App\Services\Payments\PaymentProcessorResolver::class, function ($mock) {
            $processor = \Mockery::mock(\App\Contracts\PaymentProcessor::class);
            $processor->shouldReceive('charge')
                ->once()
                ->andThrow(new \RuntimeException('Gateway connection timed out.'));

            $mock->shouldReceive('resolve')
                ->once()
                ->andReturn($processor);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gateway connection timed out.');

        // 2. Resolve service after the mock is registered in the container
        $service = app(PaymentAttemptService::class);
        $service->process($attempt);
    }
}