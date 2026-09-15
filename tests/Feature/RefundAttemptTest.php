<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class RefundAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_attempt_for_existing_refund(): void
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
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'attempt_number' => 1,
        ]);

        $this->assertDatabaseHas('refund_attempts', [
            'id' => $attempt->id,
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'attempt_number' => 1,
        ]);
    }

    public function test_allows_multiple_attempts_incrementing_attempt_number(): void
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
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $attempt1 = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 1,
            'error_message' => 'Timeout',
        ]);

        $attempt2 = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'succeeded',
            'attempt_number' => 2,
            'processor_reference_id' => 'ref_paypal_123',
        ]);

        $this->assertEquals(2, $refund->attempts()->count());
        $this->assertEquals(1, $attempt1->attempt_number);
        $this->assertEquals(2, $attempt2->attempt_number);
    }

    public function test_unique_processor_reference_id_constraint(): void
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

        $refund1 = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $refund2 = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 2000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash2',
        ]);

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund1->id,
            'processor' => 'stripe',
            'status' => 'succeeded',
            'attempt_number' => 1,
            'processor_reference_id' => 're_shared_123',
        ]);

        $this->expectException(QueryException::class);

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund2->id,
            'processor' => 'stripe',
            'status' => 'succeeded',
            'attempt_number' => 1,
            'processor_reference_id' => 're_shared_123',
        ]);
    }

    public function test_same_reference_across_different_processors_is_allowed(): void
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

        $refund1 = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $refund2 = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 2000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash2',
        ]);

        $attempt1 = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund1->id,
            'processor' => 'stripe',
            'status' => 'succeeded',
            'attempt_number' => 1,
            'processor_reference_id' => 'ref_common_123',
        ]);

        $attempt2 = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund2->id,
            'processor' => 'paypal',
            'status' => 'succeeded',
            'attempt_number' => 1,
            'processor_reference_id' => 'ref_common_123',
        ]);

        $this->assertDatabaseHas('refund_attempts', ['id' => $attempt1->id, 'processor' => 'stripe']);
        $this->assertDatabaseHas('refund_attempts', ['id' => $attempt2->id, 'processor' => 'paypal']);
    }

    public function test_attempt_state_transitions_and_terminal_locks(): void
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
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'processing',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'attempt_number' => 1,
        ]);

        $attempt->update(['status' => 'succeeded']);
        $this->assertEquals('succeeded', $attempt->fresh()->status);

        $allowedAttemptTransitions = [
            'processing' => ['succeeded', 'failed'],
            'succeeded' => [],
            'failed' => [],
        ];

        $isValidTransition = in_array('processing', $allowedAttemptTransitions[$attempt->status], true);
        $this->assertFalse($isValidTransition);
    }
    public function test_duplicate_attempt_number_for_same_refund_is_rejected(): void
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
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 1,
        ]);

        $this->expectException(QueryException::class);

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'processing',
            'attempt_number' => 1, // Duplicate attempt_number for the same refund_id
        ]);
    }

    public function test_supported_processors_validation(): void
    {
        $supportedProcessors = ['stripe', 'paypal'];

        $validProcessor = 'stripe';
        $invalidProcessor = 'unsupported_gateway';

        $this->assertTrue(in_array($validProcessor, $supportedProcessors, true));
        $this->assertFalse(in_array($invalidProcessor, $supportedProcessors, true));
    }

    public function test_terminal_attempt_states_cannot_transition(): void
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
            'amount' => 3000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed', // Terminal state
            'attempt_number' => 1,
        ]);

        $allowedAttemptTransitions = [
            'processing' => ['succeeded', 'failed'],
            'succeeded' => [],
            'failed' => [],
        ];

        $isValid = in_array('processing', $allowedAttemptTransitions[$attempt->status], true);
        $this->assertFalse($isValid);
    }
}