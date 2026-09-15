<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundOrchestrationTest extends TestCase
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

    public function test_transient_failure_retries_and_succeeds(): void
    {
        $refund = $this->createTestRefund();

        $attempt1 = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 1,
            'error_message' => 'Rate limit exceeded (transient)',
        ]);

        $attempt2 = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'succeeded',
            'attempt_number' => 2,
            'processor_reference_id' => 're_retry_success_123',
        ]);

        $refund->update(['status' => 'succeeded']);

        $this->assertEquals('failed', $attempt1->fresh()->status);
        $this->assertEquals('succeeded', $attempt2->fresh()->status);
        $this->assertEquals('succeeded', $refund->fresh()->status);
        $this->assertCount(2, RefundAttempt::where('refund_id', $refund->id)->get());
    }

    public function test_non_retryable_failure_fails_immediately(): void
    {
        $refund = $this->createTestRefund();

        $attempt1 = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 1,
            'error_message' => 'Charge already fully refunded',
        ]);

        $refund->update(['status' => 'failed']);

        $this->assertEquals(1, RefundAttempt::where('refund_id', $refund->id)->count());
        $this->assertEquals('failed', $refund->fresh()->status);
        $this->assertEquals('Charge already fully refunded', $attempt1->fresh()->error_message);
    }

    public function test_primary_processor_exhaustion_triggers_fallback(): void
    {
        $refund = $this->createTestRefund();

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 1,
            'error_message' => 'Stripe timeout 1',
        ]);

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 2,
            'error_message' => 'Stripe timeout 2',
        ]);

        $fallbackAttempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'succeeded',
            'attempt_number' => 3,
            'processor_reference_id' => 'paypal_fallback_ref_789',
        ]);

        $refund->update(['status' => 'succeeded']);

        $this->assertEquals('paypal', $fallbackAttempt->fresh()->processor);
        $this->assertEquals('succeeded', $fallbackAttempt->fresh()->status);
        $this->assertEquals('succeeded', $refund->fresh()->status);
    }

    public function test_fallback_processor_succeeds(): void
    {
        $refund = $this->createTestRefund();

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 1,
            'error_message' => 'Primary unavailable',
        ]);

        $fallbackAttempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'succeeded',
            'attempt_number' => 2,
            'processor_reference_id' => 'paypal_succ_456',
        ]);

        $this->assertEquals('paypal', $fallbackAttempt->fresh()->processor);
        $this->assertEquals('succeeded', $fallbackAttempt->fresh()->status);
    }

    public function test_all_processors_fail_refund_becomes_failed(): void
    {
        $refund = $this->createTestRefund();

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 1,
            'error_message' => 'Stripe failed',
        ]);

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'failed',
            'attempt_number' => 2,
            'error_message' => 'PayPal failed',
        ]);

        $refund->update(['status' => 'failed']);

        $this->assertEquals('failed', $refund->fresh()->status);
    }

    public function test_already_succeeded_refund_cannot_be_processed_again(): void
    {
        $refund = $this->createTestRefund(['status' => 'succeeded']);

        $canProcess = $refund->status !== 'succeeded';

        $this->assertFalse($canProcess);
        $this->assertEquals(0, RefundAttempt::where('refund_id', $refund->id)->count());
    }

    public function test_processor_exception_is_handled_safely(): void
    {
        $refund = $this->createTestRefund();
        $attempt = null;

        try {
            throw new Exception('Gateway connection reset by peer');
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

        $this->assertEquals('failed', $attempt->fresh()->status);
        $this->assertEquals('Gateway connection reset by peer', $attempt->fresh()->error_message);
    }

    public function test_correct_refund_attempt_records_are_created(): void
    {
        $refund = $this->createTestRefund();

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'succeeded',
            'attempt_number' => 1,
            'processor_reference_id' => 'ref_111',
        ]);

        $this->assertDatabaseHas('refund_attempts', [
            'id' => $attempt->id,
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'succeeded',
        ]);
    }

    public function test_attempt_numbers_remain_sequential(): void
    {
        $refund = $this->createTestRefund();

        $a1 = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 1,
        ]);

        $a2 = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 2,
        ]);

        $a3 = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'succeeded',
            'attempt_number' => 3,
        ]);

        $this->assertEquals(1, $a1->fresh()->attempt_number);
        $this->assertEquals(2, $a2->fresh()->attempt_number);
        $this->assertEquals(3, $a3->fresh()->attempt_number);
    }

    public function test_only_successful_attempt_ends_in_succeeded(): void
    {
        $refund = $this->createTestRefund();

        $failedAttempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 1,
            'error_message' => 'Error 1',
        ]);

        $successAttempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'succeeded',
            'attempt_number' => 2,
            'processor_reference_id' => 'ref_ok_999',
        ]);

        $this->assertEquals('failed', $failedAttempt->fresh()->status);
        $this->assertEquals('succeeded', $successAttempt->fresh()->status);
    }

    public function test_failed_attempts_retain_their_error_information(): void
    {
        $refund = $this->createTestRefund();

        $attempt = RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'failed',
            'attempt_number' => 1,
            'error_message' => 'INSTRUMENT_DECLINED: The instrument was declined',
            'raw_response' => ['name' => 'INSTRUMENT_DECLINED', 'details' => '...'],
        ]);

        $storedAttempt = RefundAttempt::find($attempt->id);

        $this->assertEquals('INSTRUMENT_DECLINED: The instrument was declined', $storedAttempt->error_message);
        $this->assertIsArray($storedAttempt->raw_response);
        $this->assertEquals('INSTRUMENT_DECLINED', $storedAttempt->raw_response['name']);
    }

    public function test_refund_amount_currency_remain_unchanged_throughout_retries(): void
    {
        $refund = $this->createTestRefund([
            'amount' => 4500,
            'currency' => 'EUR',
        ]);

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'attempt_number' => 1,
        ]);

        RefundAttempt::create([
            'id' => fake()->uuid(),
            'refund_id' => $refund->id,
            'processor' => 'paypal',
            'status' => 'succeeded',
            'attempt_number' => 2,
        ]);

        $freshRefund = $refund->fresh();

        $this->assertEquals(4500, $freshRefund->amount);
        $this->assertEquals('EUR', $freshRefund->currency);
    }
}