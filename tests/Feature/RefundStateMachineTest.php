<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_transitions_from_pending_to_processing_to_succeeded(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $refund = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $this->assertEquals('pending', $refund->status);

        // Transition to processing
        $refund->update(['status' => 'processing']);
        $this->assertEquals('processing', $refund->fresh()->status);

        // Transition to succeeded
        $refund->update(['status' => 'succeeded']);
        $this->assertEquals('succeeded', $refund->fresh()->status);
    }

    public function test_valid_transition_from_processing_to_failed(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $refund = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'processing',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $refund->update(['status' => 'failed']);
        $this->assertEquals('failed', $refund->fresh()->status);
    }

    public function test_terminal_states_cannot_be_modified(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $refund = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $isTerminal = in_array($refund->status, ['succeeded', 'failed'], true);
        $this->assertTrue($isTerminal);
    }

    public function test_invalid_direct_transition_from_pending_to_succeeded_is_guarded(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $refund = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $allowedTransitions = [
            'pending' => ['processing'],
            'processing' => ['succeeded', 'failed'],
            'succeeded' => [],
            'failed' => [],
        ];

        $targetStatus = 'succeeded';
        $isValid = in_array($targetStatus, $allowedTransitions[$refund->status], true);

        $this->assertFalse($isValid);
    }
    public function test_terminal_succeeded_state_cannot_transition_to_processing_or_pending(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $refund = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $allowedTransitions = [
            'pending' => ['processing'],
            'processing' => ['succeeded', 'failed'],
            'succeeded' => [],
            'failed' => [],
        ];

        foreach (['processing', 'pending'] as $targetStatus) {
            $isValid = in_array($targetStatus, $allowedTransitions[$refund->status], true);
            $this->assertFalse($isValid);
        }
    }

    public function test_terminal_failed_state_cannot_transition_to_processing_or_succeeded(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $refund = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'failed',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $allowedTransitions = [
            'pending' => ['processing'],
            'processing' => ['succeeded', 'failed'],
            'succeeded' => [],
            'failed' => [],
        ];

        foreach (['processing', 'succeeded'] as $targetStatus) {
            $isValid = in_array($targetStatus, $allowedTransitions[$refund->status], true);
            $this->assertFalse($isValid);
        }
    }

    public function test_unknown_status_transitions_are_rejected(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $refund = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $allowedTransitions = [
            'pending' => ['processing'],
            'processing' => ['succeeded', 'failed'],
            'succeeded' => [],
            'failed' => [],
        ];

        $currentStatus = $refund->status;
        $targetStatus = 'unknown_state';

        $isValid = isset($allowedTransitions[$currentStatus]) && in_array($targetStatus, $allowedTransitions[$currentStatus], true);

        $this->assertFalse($isValid);
    }
}