<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\Merchant;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentIntentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_pending_to_processing_transition(): void
    {
        $intent = $this->createIntentWithStatus('pending');

        $intent->transitionTo('processing');

        $this->assertEquals('processing', $intent->fresh()->status);
    }

    public function test_it_allows_pending_to_failed_transition(): void
    {
        $intent = $this->createIntentWithStatus('pending');

        $intent->transitionTo('failed');

        $this->assertEquals('failed', $intent->fresh()->status);
    }

    public function test_it_allows_pending_to_canceled_transition(): void
    {
        $intent = $this->createIntentWithStatus('pending');

        $intent->transitionTo('canceled');

        $this->assertEquals('canceled', $intent->fresh()->status);
    }

    public function test_it_allows_processing_to_succeeded_transition(): void
    {
        $intent = $this->createIntentWithStatus('processing');

        $intent->transitionTo('succeeded');

        $this->assertEquals('succeeded', $intent->fresh()->status);
    }

    public function test_it_allows_processing_to_failed_transition(): void
    {
        $intent = $this->createIntentWithStatus('processing');

        $intent->transitionTo('failed');

        $this->assertEquals('failed', $intent->fresh()->status);
    }

    public function test_it_prevents_moving_backward_from_processing_to_pending(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Invalid status transition from 'processing' to 'pending'.");

        $intent = $this->createIntentWithStatus('processing');

        $intent->transitionTo('pending');
    }

    public function test_it_prevents_transitioning_from_terminal_succeeded_state(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Invalid status transition from 'succeeded' to 'pending'.");

        $intent = $this->createIntentWithStatus('succeeded');

        $intent->transitionTo('pending');
    }

    public function test_it_prevents_transitioning_from_terminal_failed_state(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Invalid status transition from 'failed' to 'processing'.");

        $intent = $this->createIntentWithStatus('failed');

        $intent->transitionTo('processing');
    }

    public function test_it_prevents_transitioning_from_terminal_canceled_state(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Invalid status transition from 'canceled' to 'succeeded'.");

        $intent = $this->createIntentWithStatus('canceled');

        $intent->transitionTo('succeeded');
    }

    private function createIntentWithStatus(string $status): PaymentIntent
    {
        $merchant = Merchant::factory()->create();

        return PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 1000,
            'currency' => 'USD',
            'status' => $status,
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'dummyhash',
        ]);
    }
}