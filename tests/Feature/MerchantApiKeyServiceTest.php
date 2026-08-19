<?php

use App\Models\Merchant;
use App\Services\MerchantApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('generates and stores a hashed test api key', function () {
    $merchant = Merchant::factory()->create();

    $service = new MerchantApiKeyService();

    $rawKey = $service->generate($merchant);

    $merchant->refresh();

    expect($rawKey)
        ->toStartWith('pk_test_')
        ->toHaveLength(72);

    expect($merchant->api_key_hash)
        ->not->toBeNull()
        ->toBe(hash('sha256', $rawKey));

    expect($merchant->api_key_hash)
        ->not->toBe($rawKey);
});
test('regenerates the api key and invalidates the previous key', function () {
    $merchant = Merchant::factory()->create();

    $service = new MerchantApiKeyService();

    $oldKey = $service->generate($merchant);
    $oldHash = $merchant->fresh()->api_key_hash;

    $newKey = $service->generate($merchant);
    $newHash = $merchant->fresh()->api_key_hash;

    expect($newKey)
        ->not->toBe($oldKey)
        ->toStartWith('pk_test_')
        ->toHaveLength(72);

    expect($newHash)
        ->toBe(hash('sha256', $newKey))
        ->not->toBe($oldHash);

    expect($newHash)
        ->not->toBe(hash('sha256', $oldKey));
});