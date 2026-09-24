<?php

namespace Tests\Feature;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_endpoint_throttles_excessive_requests(): void
    {
        $merchant = Merchant::factory()->create();
        $token    = 'test-token-' . $merchant->id;

        $attempts = 0;
        $hitLimit = false;

        for ($i = 0; $i < 100; $i++) {
            $response = $this->withHeaders([
                'Authorization'   => 'Bearer ' . $token,
                'Accept'          => 'application/json',
                'Idempotency-Key' => (string) Str::uuid(),
            ])->postJson('/api/v1/refunds', [
                'payment_intent_id' => (string) Str::uuid(),
                'amount'            => 100,
            ]);

            if ($response->status() === 429) {
                $hitLimit = true;
                break;
            }

            $attempts++;
        }

        $this->assertTrue($hitLimit, 'Expected to hit a 429 within 100 requests.');
        $this->assertLessThan(100, $attempts, 'Rate limit fired too late.');
    }

    public function test_rate_limit_returns_retry_after_header(): void
    {
        $merchant = Merchant::factory()->create();
        $token    = 'test-token-' . $merchant->id;

        for ($i = 0; $i < 100; $i++) {
            $response = $this->withHeaders([
                'Authorization'   => 'Bearer ' . $token,
                'Idempotency-Key' => (string) Str::uuid(),
            ])->postJson('/api/v1/refunds', [
                'payment_intent_id' => (string) Str::uuid(),
                'amount'            => 100,
            ]);

            if ($response->status() === 429) {
                $response->assertHeader('Retry-After');
                return;
            }
        }

        $this->fail('Rate limit was never hit.');
    }
}