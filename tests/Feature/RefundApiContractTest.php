<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundApiContractTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create(); // status = 'active'
        $this->token    = 'test-token-' . $this->merchant->id;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function authHeaders(?string $token = null, ?string $idempotencyKey = null): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }

    private function otherMerchant(): array
    {
        $merchant = Merchant::factory()->create();

        return [$merchant, 'test-token-' . $merchant->id];
    }

    private function makeSucceededPayment(
        Merchant $merchant,
        int $amount = 10000,
        string $currency = 'PKR'
    ): PaymentIntent {
        return PaymentIntent::factory()->create([
            'merchant_id'     => $merchant->id,
            'amount'          => $amount,
            'currency'        => $currency,
            'status'          => 'succeeded',
            'idempotency_key' => (string) Str::uuid(),
            'request_hash'    => hash('sha256', 'seed'),
        ]);
    }

    private function makeExistingRefund(
        PaymentIntent $payment,
        Merchant $merchant,
        int $amount,
        string $status = 'succeeded'
    ): Refund {
        return Refund::create([
            'payment_intent_id' => $payment->id,
            'merchant_id'       => $merchant->id,
            'amount'            => $amount,
            'currency'          => $payment->currency,
            'status'            => $status,
            'reason'            => 'seed',
            'idempotency_key'   => (string) Str::uuid(),
            'request_hash'      => hash('sha256', 'seed-' . Str::uuid()),
        ]);
    }

    private function postRefund(array $payload, array $headers)
    {
        return $this->postJson('/api/v1/refunds', $payload, $headers);
    }

    // ---------------------------------------------------------------------
    // 1. Happy path
    // ---------------------------------------------------------------------

    public function test_authenticated_merchant_can_create_a_refund(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant, 10000, 'PKR');

        $response = $this->postRefund(
            [
                'payment_intent_id' => $payment->id,
                'amount'            => 3000,
                'reason'            => 'customer_request',
            ],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        $response->assertStatus(201)
                 ->assertJsonPath('payment_intent_id', $payment->id)
                 ->assertJsonPath('amount', 3000)
                 ->assertJsonPath('currency', 'PKR');

        $this->assertDatabaseHas('refunds', [
            'payment_intent_id' => $payment->id,
            'merchant_id'       => $this->merchant->id,
            'amount'            => 3000,
        ]);
    }

    // ---------------------------------------------------------------------
    // 2–5. Auth & idempotency-key header
    // ---------------------------------------------------------------------

    public function test_missing_authentication_returns_401(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant);

        $response = $this->postRefund(
            ['payment_intent_id' => $payment->id, 'amount' => 1000],
            $this->authHeaders(null, (string) Str::uuid())
        );

        $response->assertStatus(401);
    }

    public function test_invalid_api_key_returns_401(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant);

        // A well-formed test token pointing at a merchant that doesn't exist.
        $response = $this->postRefund(
            ['payment_intent_id' => $payment->id, 'amount' => 1000],
            $this->authHeaders('test-token-' . (string) Str::uuid(), (string) Str::uuid())
        );

        $response->assertStatus(401);
    }

    public function test_missing_idempotency_key_returns_422(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant);

        $response = $this->postRefund(
            ['payment_intent_id' => $payment->id, 'amount' => 1000],
            $this->authHeaders($this->token, null)
        );

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_invalid_idempotency_uuid_returns_422(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant);

        $response = $this->postRefund(
            ['payment_intent_id' => $payment->id, 'amount' => 1000],
            $this->authHeaders($this->token, 'not-a-uuid')
        );

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('idempotency_key');
    }

    // ---------------------------------------------------------------------
    // 6–9. Body validation
    // ---------------------------------------------------------------------

    public function test_missing_payment_intent_id_returns_422(): void
    {
        $response = $this->postRefund(
            ['amount' => 1000],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('payment_intent_id');
    }

    public function test_invalid_payment_intent_uuid_returns_422(): void
    {
        $response = $this->postRefund(
            ['payment_intent_id' => 'not-a-uuid', 'amount' => 1000],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('payment_intent_id');
    }

    public function test_missing_amount_returns_422(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant);

        $response = $this->postRefund(
            ['payment_intent_id' => $payment->id],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('amount');
    }

    public function test_zero_or_negative_amount_returns_422(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant);

        foreach ([0, -1, -3000] as $bad) {
            $response = $this->postRefund(
                ['payment_intent_id' => $payment->id, 'amount' => $bad],
                $this->authHeaders($this->token, (string) Str::uuid())
            );

            $response->assertStatus(422)
                     ->assertJsonValidationErrors('amount');
        }
    }

    // ---------------------------------------------------------------------
    // 10–12. Ownership & payment state
    // ---------------------------------------------------------------------

    public function test_refund_for_nonexistent_payment_returns_404(): void
    {
        $response = $this->postRefund(
            ['payment_intent_id' => (string) Str::uuid(), 'amount' => 1000],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        $response->assertStatus(404);
    }

    public function test_refund_for_another_merchants_payment_returns_404(): void
    {
        [$other, $otherToken] = $this->otherMerchant();
        $payment = $this->makeSucceededPayment($other);

        $response = $this->postRefund(
            ['payment_intent_id' => $payment->id, 'amount' => 1000],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        // 404, not 403 — we must not leak the existence of another merchant's PI.
        $response->assertStatus(404);
    }

    public function test_refund_for_non_succeeded_payment_returns_422(): void
    {
        $payment = PaymentIntent::factory()->create([
            'merchant_id'     => $this->merchant->id,
            'amount'          => 10000,
            'currency'        => 'PKR',
            'status'          => 'requires_payment_method',
            'idempotency_key' => (string) Str::uuid(),
            'request_hash'    => hash('sha256', 'seed'),
        ]);

        $response = $this->postRefund(
            ['payment_intent_id' => $payment->id, 'amount' => 1000],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // 13. Amount ceiling
    // ---------------------------------------------------------------------

    public function test_refund_exceeding_remaining_refundable_amount_returns_422(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant, 10000);

        $this->makeExistingRefund($payment, $this->merchant, 8000);

        $response = $this->postRefund(
            ['payment_intent_id' => $payment->id, 'amount' => 3000],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // 14. Response shape
    // ---------------------------------------------------------------------

    public function test_successful_refund_returns_expected_response_shape(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant, 10000);

        $response = $this->postRefund(
            [
                'payment_intent_id' => $payment->id,
                'amount'            => 3000,
                'reason'            => 'customer_request',
            ],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'id',
                     'payment_intent_id',
                     'amount',
                     'currency',
                     'status',
                     'reason',
                     'created_at',
                 ]);
    }

    // ---------------------------------------------------------------------
    // 15–17. Idempotency semantics
    // ---------------------------------------------------------------------

    public function test_same_idempotency_key_with_same_parameters_returns_same_refund(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant, 10000);
        $key = (string) Str::uuid();

        $payload = [
            'payment_intent_id' => $payment->id,
            'amount'            => 3000,
            'reason'            => 'customer_request',
        ];

        $first  = $this->postRefund($payload, $this->authHeaders($this->token, $key));
        $second = $this->postRefund($payload, $this->authHeaders($this->token, $key));

        $first->assertStatus(201);
        $this->assertContains($second->status(), [200, 201]);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, Refund::where('idempotency_key', $key)->count());
    }

    public function test_same_idempotency_key_with_different_amount_returns_409(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant, 10000);
        $key = (string) Str::uuid();

        $this->postRefund(
            ['payment_intent_id' => $payment->id, 'amount' => 3000],
            $this->authHeaders($this->token, $key)
        )->assertStatus(201);

        $this->postRefund(
            ['payment_intent_id' => $payment->id, 'amount' => 4000],
            $this->authHeaders($this->token, $key)
        )->assertStatus(409);
    }

    public function test_different_merchants_can_use_the_same_idempotency_key(): void
    {
        [$other, $otherToken] = $this->otherMerchant();

        $key = (string) Str::uuid();

        $p1 = $this->makeSucceededPayment($this->merchant, 10000);
        $p2 = $this->makeSucceededPayment($other, 10000);

        $this->postRefund(
            ['payment_intent_id' => $p1->id, 'amount' => 3000],
            $this->authHeaders($this->token, $key)
        )->assertStatus(201);

        $this->postRefund(
            ['payment_intent_id' => $p2->id, 'amount' => 3000],
            $this->authHeaders($otherToken, $key)
        )->assertStatus(201);

        $this->assertSame(2, Refund::where('idempotency_key', $key)->count());
    }

    // ---------------------------------------------------------------------
    // 18–20. Hardening
    // ---------------------------------------------------------------------

    public function test_internal_fields_are_not_exposed(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant, 10000);

        $response = $this->postRefund(
            ['payment_intent_id' => $payment->id, 'amount' => 3000],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        $response->assertStatus(201);

        $json = $response->json();
        foreach (['request_hash', 'idempotency_key', 'merchant_id', 'deleted_at', 'updated_at'] as $leak) {
            $this->assertArrayNotHasKey($leak, $json, "Leaked internal field: {$leak}");
        }
    }

    public function test_currency_comes_from_payment_not_client(): void
    {
        $payment = $this->makeSucceededPayment($this->merchant, 10000, 'PKR');

        $response = $this->postRefund(
            [
                'payment_intent_id' => $payment->id,
                'amount'            => 3000,
                'currency'          => 'USD',
            ],
            $this->authHeaders($this->token, (string) Str::uuid())
        );

        if ($response->status() === 201) {
            $response->assertJsonPath('currency', 'PKR');
            $this->assertDatabaseHas('refunds', [
                'payment_intent_id' => $payment->id,
                'currency'          => 'PKR',
            ]);
        } else {
            $response->assertStatus(422);
        }
    }

    public function test_merchant_id_in_body_is_rejected_at_validation(): void
{
    [$other, $otherToken] = $this->otherMerchant();
    $payment = $this->makeSucceededPayment($other);

    $response = $this->postRefund(
        [
            'payment_intent_id' => $payment->id,
            'amount'            => 3000,
            'merchant_id'       => $this->merchant->id,
        ],
        $this->authHeaders($this->token, (string) Str::uuid())
    );

    // Prohibited field → 422 before the controller runs.
    $response->assertStatus(422)
             ->assertJsonValidationErrors('merchant_id');

    // The other merchant's payment is untouched.
    $this->assertDatabaseMissing('refunds', [
        'payment_intent_id' => $payment->id,
    ]);
}
}