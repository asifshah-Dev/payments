<?php

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Services\MerchantApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function authenticatedMerchant(): array
{
    $merchant = Merchant::factory()->create([
        'status' => 'active',
    ]);

    $service = new MerchantApiKeyService();

    $apiKey = $service->generate($merchant);

    return [$merchant, $apiKey];
}

test('creates a payment intent through the API', function () {
    $this->withoutExceptionHandling();

    [$merchant, $apiKey] = authenticatedMerchant();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $apiKey,
        'Idempotency-Key' => fake()->uuid(),
    ])->postJson('/api/v1/payment-intents', [
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Test payment',
    ]);

    $response
        ->assertStatus(201)
        ->assertJson([
            'merchant_id' => $merchant->id,
            'amount' => 5000,
            'currency' => 'USD',
            'description' => 'Test payment',
            'status' => 'pending',
        ]);

    expect(PaymentIntent::count())->toBe(1);
});

test('does not allow merchant id in the request body to control ownership', function () {
    [$merchant, $apiKey] = authenticatedMerchant();

    $otherMerchant = Merchant::factory()->create([
        'status' => 'active',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $apiKey,
        'Idempotency-Key' => fake()->uuid(),
    ])->postJson('/api/v1/payment-intents', [
        'merchant_id' => $otherMerchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Test payment',
    ]);

    $response
        ->assertStatus(201)
        ->assertJson([
            'merchant_id' => $merchant->id,
        ]);

    expect(PaymentIntent::first()->merchant_id)
        ->toBe($merchant->id);
});

test('requires idempotency key header', function () {
    [$merchant, $apiKey] = authenticatedMerchant();

    $response = $this->withHeader(
        'Authorization',
        'Bearer ' . $apiKey
    )->postJson('/api/v1/payment-intents', [
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Test payment',
    ]);

    $response->assertStatus(422);
});

test('rejects request without authentication', function () {
    $response = $this->withHeader(
        'Idempotency-Key',
        fake()->uuid()
    )->postJson('/api/v1/payment-intents', [
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Test payment',
    ]);

    $response->assertStatus(401);
});

test('returns the existing payment intent for the same idempotency key', function () {
    [$merchant, $apiKey] = authenticatedMerchant();

    $idempotencyKey = fake()->uuid();

    $headers = [
        'Authorization' => 'Bearer ' . $apiKey,
        'Idempotency-Key' => $idempotencyKey,
    ];

    $payload = [
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Test payment',
    ];

    $firstResponse = $this
        ->withHeaders($headers)
        ->postJson('/api/v1/payment-intents', $payload);

    $firstResponse->assertStatus(201);

    $firstId = $firstResponse->json('id');

    $secondResponse = $this
        ->withHeaders($headers)
        ->postJson('/api/v1/payment-intents', $payload);

    $secondResponse
        ->assertStatus(201)
        ->assertJson([
            'id' => $firstId,
        ]);

    expect(PaymentIntent::count())->toBe(1);
});

test('rejects the same idempotency key when the request is different', function () {
    [$merchant, $apiKey] = authenticatedMerchant();

    $idempotencyKey = fake()->uuid();

    $headers = [
        'Authorization' => 'Bearer ' . $apiKey,
        'Idempotency-Key' => $idempotencyKey,
    ];

    $firstResponse = $this
        ->withHeaders($headers)
        ->postJson('/api/v1/payment-intents', [
            'amount' => 5000,
            'currency' => 'USD',
            'description' => 'Original payment',
        ]);

    $firstResponse->assertStatus(201);

    $secondResponse = $this
        ->withHeaders($headers)
        ->postJson('/api/v1/payment-intents', [
            'amount' => 7000,
            'currency' => 'USD',
            'description' => 'Different payment',
        ]);

    $secondResponse
    ->assertStatus(409)
    ->assertJson([
        'message' => 'Idempotency key was already used with a different request.',
    ]);
});

test('allows the same idempotency key for different merchants', function () {
    [$merchantA, $apiKeyA] = authenticatedMerchant();
    [$merchantB, $apiKeyB] = authenticatedMerchant();

    $idempotencyKey = fake()->uuid();

    $payload = [
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Test payment',
    ];

    $responseA = $this->withHeaders([
        'Authorization' => 'Bearer ' . $apiKeyA,
        'Idempotency-Key' => $idempotencyKey,
    ])->postJson('/api/v1/payment-intents', $payload);

    $responseA->assertStatus(201);

    $responseB = $this->withHeaders([
        'Authorization' => 'Bearer ' . $apiKeyB,
        'Idempotency-Key' => $idempotencyKey,
    ])->postJson('/api/v1/payment-intents', $payload);

    $responseB
        ->assertStatus(201)
        ->assertJson([
            'merchant_id' => $merchantB->id,
        ]);

    expect(PaymentIntent::count())->toBe(2);
});