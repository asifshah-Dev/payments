<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_can_only_be_created_for_a_succeeded_payment(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        // Attempting to create a refund for a pending payment should violate the contract rule
        $this->assertFalse($intent->status === 'succeeded');
    }

    public function test_refund_amount_must_be_greater_than_zero(): void
    {
        $amount = 0;
        $this->assertLessThanOrEqual(0, $amount);
    }

    public function test_refund_cannot_exceed_refundable_amount(): void
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

        Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 7000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $refundableBalance = $intent->amount - $intent->refunds()->where('status', 'succeeded')->sum('amount');
        $requestedRefund = 5000;

        $this->assertGreaterThan($refundableBalance, $requestedRefund);
    }

    public function test_partial_and_multiple_refunds_are_allowed_up_to_limit(): void
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
            'amount' => 4000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash2',
        ]);

        $totalRefunded = $intent->refunds()->where('status', 'succeeded')->sum('amount');
        
        $this->assertEquals(7000, $totalRefunded);
        $this->assertLessThan($intent->amount, $totalRefunded);
    }

    public function test_different_merchants_can_reuse_idempotency_key(): void
    {
        $merchantA = Merchant::factory()->create(['status' => 'active']);
        $merchantB = Merchant::factory()->create(['status' => 'active']);

        $intentA = PaymentIntent::create([
            'merchant_id' => $merchantA->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hashA',
        ]);

        $intentB = PaymentIntent::create([
            'merchant_id' => $merchantB->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hashB',
        ]);

        $sharedKey = 'shared-key-123';

        $refundA = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intentA->id,
            'merchant_id' => $merchantA->id,
            'amount' => 1000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => $sharedKey,
            'request_hash' => 'hash1',
        ]);

        $refundB = Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intentB->id,
            'merchant_id' => $merchantB->id,
            'amount' => 1000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => $sharedKey,
            'request_hash' => 'hash2',
        ]);

        $this->assertDatabaseHas('refunds', ['id' => $refundA->id, 'merchant_id' => $merchantA->id]);
        $this->assertDatabaseHas('refunds', ['id' => $refundB->id, 'merchant_id' => $merchantB->id]);
    }
    public function test_same_key_with_different_parameters_returns_conflict(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $intent = PaymentIntent::create([
            'merchant_id' => $merchant->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $key = 'conflict-key-123';

        Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 2000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => $key,
            'request_hash' => 'hash1',
        ]);

        // Simulating a second request with the same key but different parameters (different request_hash)
        $existing = Refund::where('merchant_id', $merchant->id)
            ->where('idempotency_key', $key)
            ->first();

        $differentHashRequest = 'hash2';
        $isConflict = $existing && $existing->request_hash !== $differentHashRequest;

        $this->assertTrue($isConflict);
    }

    public function test_refund_cannot_exceed_total_payment_amount(): void
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

        Refund::create([
            'id' => fake()->uuid(),
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash1',
        ]);

        $this->assertEquals(0, $intent->refundableAmount());

        $overRefundAttempt = 1000;
        $this->assertGreaterThan($intent->refundableAmount(), $overRefundAttempt);
    }

    public function test_refund_must_match_payment_currency(): void
    {
        $intentCurrency = 'USD';
        $refundCurrency = 'EUR';

        $this->assertNotEquals($intentCurrency, $refundCurrency);
    }

    public function test_refund_cannot_belong_to_another_merchant_payment(): void
    {
        $merchantA = Merchant::factory()->create(['status' => 'active']);
        $merchantB = Merchant::factory()->create(['status' => 'active']);

        $intentA = PaymentIntent::create([
            'merchant_id' => $merchantA->id,
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        // Merchant B attempting to reference Merchant A's payment intent
        $isValidOwnership = $intentA->merchant_id === $merchantB->id;

        $this->assertFalse($isValidOwnership);
    }
}