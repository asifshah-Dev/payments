<?php

use App\Models\Merchant;
use App\Services\MerchantApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('rejects request without authorization header', function () {
    $response = $this->getJson('/api/v1/test');

    $response->assertStatus(401)
        ->assertJson([
            'message' => 'Unauthenticated.',
        ]);
});

test('rejects request with invalid api key', function () {
    $response = $this->withHeader(
        'Authorization',
        'Bearer invalid-key'
    )->getJson('/api/v1/test');

    $response->assertStatus(401)
        ->assertJson([
            'message' => 'Unauthenticated.',
        ]);
});

test('rejects inactive merchant', function () {
    $merchant = Merchant::factory()->create([
        'status' => 'inactive',
    ]);

    $service = new MerchantApiKeyService();

    $apiKey = $service->generate($merchant);

    $response = $this->withHeader(
        'Authorization',
        'Bearer ' . $apiKey
    )->getJson('/api/v1/test');

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'Merchant account is inactive.',
        ]);
});

test('accepts valid api key for active merchant', function () {
    $merchant = Merchant::factory()->create([
        'status' => 'active',
    ]);

    $service = new MerchantApiKeyService();

    $apiKey = $service->generate($merchant);

    $response = $this->withHeader(
        'Authorization',
        'Bearer ' . $apiKey
    )->getJson('/api/v1/test');

    $response->assertStatus(200);
});
test('attaches the authenticated merchant to the request', function () {
    $merchant = Merchant::factory()->create([
        'status' => 'active',
    ]);

    $service = new MerchantApiKeyService();

    $apiKey = $service->generate($merchant);

    $response = $this->withHeader(
        'Authorization',
        'Bearer ' . $apiKey
    )->getJson('/api/v1/test');

    $response
        ->assertStatus(200)
        ->assertJson([
            'merchant_id' => $merchant->id,
        ]);
});