<?php

namespace Tests\Feature;

use App\Contracts\PaymentProcessorInterface;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\PaymentOrchestrationService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transient_failure_triggers_retry_and_succeeds(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $processor = Mockery::mock(PaymentProcessorInterface::class);
        
        $processor->shouldReceive('charge')
            ->once()
            ->andThrow(new Exception('Gateway timeout error'));

        $processor->shouldReceive('charge')
            ->once()
            ->andReturn(['id' => 'ch_123']);

        $service = new PaymentOrchestrationService();
        $result = $service->process($intent, $processor);

        $this->assertEquals('ch_123', $result['id']);
        $this->assertEquals('succeeded', $intent->fresh()->status);
        $this->assertEquals(2, $intent->attempts()->count());
    }

    public function test_non_retryable_failure_fails_immediately(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $processor = Mockery::mock(PaymentProcessorInterface::class);
        $processor->shouldReceive('charge')
            ->once()
            ->andThrow(new Exception('Card declined: insufficient funds'));

        $service = new PaymentOrchestrationService();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Card declined: insufficient funds');

        try {
            $service->process($intent, $processor);
        } finally {
            $this->assertEquals('failed', $intent->fresh()->status);
            $this->assertEquals(1, $intent->attempts()->count());
        }
    }

    public function test_primary_exhaustion_triggers_fallback_processor(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $primary = Mockery::mock(PaymentProcessorInterface::class);
        $primary->shouldReceive('charge')
            ->times(3)
            ->andThrow(new Exception('Gateway timeout'));

        $fallback = Mockery::mock(PaymentProcessorInterface::class);
        $fallback->shouldReceive('charge')
            ->once()
            ->andReturn(['id' => 'ch_fallback_123']);

        $service = new PaymentOrchestrationService();
        $result = $service->process($intent, $primary, $fallback);

        $this->assertEquals('ch_fallback_123', $result['id']);
        $this->assertEquals('succeeded', $intent->fresh()->status);
        $this->assertEquals(4, $intent->attempts()->count()); // 3 primary attempts + 1 fallback attempt
    }

    public function test_terminal_state_intent_cannot_be_processed(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $processor = Mockery::mock(PaymentProcessorInterface::class);
        $processor->shouldNotReceive('charge');

        $service = new PaymentOrchestrationService();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Cannot process intent in terminal state: succeeded");

        $service->process($intent, $processor);
    }
    public function test_primary_and_fallback_exhaustion_fails_intent(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $primary = Mockery::mock(PaymentProcessorInterface::class);
        $primary->shouldReceive('charge')
            ->times(3)
            ->andThrow(new Exception('Gateway timeout'));

        $fallback = Mockery::mock(PaymentProcessorInterface::class);
        $fallback->shouldReceive('charge')
            ->once()
            ->andThrow(new Exception('Fallback gateway unavailable'));

        $service = new PaymentOrchestrationService();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Fallback gateway unavailable');

        try {
            $service->process($intent, $primary, $fallback);
        } finally {
            $this->assertEquals('failed', $intent->fresh()->status);
            $this->assertEquals(4, $intent->attempts()->count()); // 3 primary attempts + 1 fallback attempt
        }
    }
}