<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Services\Payments\PaymentOrchestrator;
use App\Services\Payments\ProcessorResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentFallbackFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_falls_back_to_secondary_processor_when_primary_fails(): void
    {
        $merchant = Merchant::factory()->create();
        
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 4500,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'dummyhash',
        ]);

        $orchestrator = app(PaymentOrchestrator::class);
        $finalAttempt = $orchestrator->processWithFallback($intent, ['mock_fail', 'mock']);

        $this->assertEquals('succeeded', $finalAttempt->status);
        $this->assertEquals('succeeded', $intent->fresh()->status);

        $attempts = $intent->paymentAttempts()->orderBy('created_at')->get();
        $this->assertCount(2, $attempts);
        
        $this->assertEquals('mock_fail', $attempts[0]->processor);
        $this->assertEquals('failed', $attempts[0]->status);

        $this->assertEquals('mock', $attempts[1]->processor);
        $this->assertEquals('succeeded', $attempts[1]->status);
    }

    public function test_it_fails_intent_completely_if_all_fallback_processors_fail(): void
    {
        $merchant = Merchant::factory()->create();
        
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 7500,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'dummyhash',
        ]);

        $orchestrator = app(PaymentOrchestrator::class);
        $finalAttempt = $orchestrator->processWithFallback($intent, ['mock_fail', 'mock_fail']);

        $this->assertEquals('failed', $finalAttempt->status);
        $this->assertEquals('failed', $intent->fresh()->status);
        $this->assertCount(2, $intent->paymentAttempts);
    }

    public function test_it_succeeds_on_third_tier_fallback(): void
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

        $orchestrator = app(PaymentOrchestrator::class);
        $finalAttempt = $orchestrator->processWithFallback($intent, ['mock_fail', 'mock_fail', 'mock']);

        $this->assertEquals('succeeded', $finalAttempt->status);
        $this->assertEquals('succeeded', $intent->fresh()->status);
        $this->assertCount(3, $intent->paymentAttempts);
    }

    public function test_it_throws_exception_if_processor_list_is_empty(): void
    {
        $merchant = Merchant::factory()->create();
        
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 1000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'dummyhash',
        ]);

        $orchestrator = app(PaymentOrchestrator::class);

        $this->expectException(InvalidArgumentException::class);
        $orchestrator->processWithFallback($intent, []);
    }

    public function test_it_recovers_from_unexpected_processor_exception_during_fallback(): void
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

        $this->mock(\App\Services\Payments\PaymentProcessorResolver::class, function ($mock) {
            $failingProcessor = \Mockery::mock(\App\Contracts\PaymentProcessor::class);
            $failingProcessor->shouldReceive('charge')->once()->andThrow(new \RuntimeException('Network timeout'));

            $successProcessor = \Mockery::mock(\App\Contracts\PaymentProcessor::class);
           $successProcessor->shouldReceive('charge')->once()->andReturn(\App\Services\ProcessorResult::success('ref_fallback_123'));

            $mock->shouldReceive('resolve')
                ->with('failing_gateway')
                ->once()
                ->andReturn($failingProcessor);

            $mock->shouldReceive('resolve')
                ->with('mock')
                ->once()
                ->andReturn($successProcessor);
        });

        $orchestrator = app(PaymentOrchestrator::class);
        $finalAttempt = $orchestrator->processWithFallback($intent, ['failing_gateway', 'mock']);

        $this->assertEquals('succeeded', $finalAttempt->status);
        $this->assertEquals('succeeded', $intent->fresh()->status);
        $this->assertCount(2, $intent->paymentAttempts);
    }
}