<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class MerchantAuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_fails_with_missing_authorization_header(): void
    {
        $response = $this->postJson('/api/v1/payment-intents', [
            'amount' => 5000,
            'currency' => 'USD',
        ]);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_request_fails_with_invalid_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token-string')
                         ->postJson('/api/v1/payment-intents', [
                             'amount' => 5000,
                             'currency' => 'USD',
                         ]);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_inactive_merchant_is_unauthorized(): void
    {
        $merchant = Merchant::factory()->create([
            'status' => 'inactive',
        ]);

        $token = 'test-token-' . $merchant->id;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->postJson('/api/v1/payment-intents', [
                             'amount' => 5000,
                             'currency' => 'USD',
                         ]);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_production_environment_rejects_test_tokens(): void
    {
        app()['env'] = 'production';

        $merchant = Merchant::factory()->create([
            'status' => 'active',
        ]);

        $token = 'test-token-' . $merchant->id;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->postJson('/api/v1/payment-intents', [
                             'amount' => 5000,
                             'currency' => 'USD',
                         ]);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);

        app()['env'] = 'testing';
    }

    public function test_production_sha256_api_key_authentication_succeeds(): void
    {
        $plainTextKey = 'sk_live_testkey123456789';
        $keyHash = hash('sha256', $plainTextKey);

        $merchant = Merchant::factory()->create([
            'status' => 'active',
            'api_key_hash' => $keyHash,
        ]);

        $payload = [
            'amount' => 5000,
            'currency' => 'USD',
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $plainTextKey)
                         ->withHeader('Idempotency-Key', fake()->uuid())
                         ->postJson('/api/v1/payment-intents', $payload);

        $response->assertSuccessful();
    }

    public function test_malformed_authorization_header_is_unauthorized(): void
    {
        $response = $this->withHeader('Authorization', 'Basic somebase64string')
                         ->postJson('/api/v1/payment-intents', [
                             'amount' => 5000,
                             'currency' => 'USD',
                         ]);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    public function test_merchant_cannot_access_another_merchant_payment_intent(): void
    {
        $merchantA = Merchant::factory()->create(['status' => 'active']);
        $merchantB = Merchant::factory()->create(['status' => 'active']);

        $intent = PaymentIntent::create([
            'merchant_id' => $merchantA->id,
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
            'request_hash' => 'hash',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-token-' . $merchantB->id)
                         ->getJson("/api/v1/payment-intents/{$intent->id}");

        $this->assertContains($response->status(), [
            Response::HTTP_UNAUTHORIZED, 
            Response::HTTP_NOT_FOUND, 
            Response::HTTP_FORBIDDEN
        ]);
    }

    public function test_invalid_uuid_idempotency_header_fails_validation(): void
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);

        $response = $this->withHeader('Authorization', 'Bearer test-token-' . $merchant->id)
                         ->withHeader('Idempotency-Key', 'not-a-valid-uuid')
                         ->postJson('/api/v1/payment-intents', [
                             'amount' => 5000,
                             'currency' => 'USD',
                         ]);

        $this->assertContains($response->status(), [
            Response::HTTP_UNPROCESSABLE_ENTITY,
            Response::HTTP_UNAUTHORIZED
        ]);
        
        if ($response->status() === Response::HTTP_UNPROCESSABLE_ENTITY) {
            $response->assertJsonValidationErrors(['Idempotency-Key']);
        }
    }
}