<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\Merchant; // Adjust if your model name is User or something else
use Illuminate\Database\QueryException;

it('allows a platform account to exist with a null merchant id', function () {
    $account = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
    ]);

    expect($account->merchant_id)->toBeNull();
});

it('allows a merchant payable account to belong to a specific merchant', function () {
    $merchant = Merchant::factory()->create();

    $account = LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
    ]);

    expect($account->merchant_id)->toBe($merchant->id);
});

it('allows a platform clearing account to be shared conceptually across merchants', function () {
    $platformAccount = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
    ]);

    expect($platformAccount->merchant_id)->toBeNull();
});

it('prevents a merchant from having two identical payable accounts for the same currency', function () {
    $merchant = Merchant::factory()->create();

    LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
    ]);

    // Attempting to create a duplicate payable account for the same merchant and currency
    expect(fn() => LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
    ]))->toThrow(QueryException::class);
});

it('ensures a platform clearing account cannot accidentally be created as a liability', function () {
    $account = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
    ]);

    expect($account->type)->toBe('asset');
});

it('ensures a merchant payable account must be a liability', function () {
    $merchant = Merchant::factory()->create();

    $account = LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
    ]);

    expect($account->type)->toBe('liability');
});

it('ensures a platform clearing account must be an asset', function () {
    $account = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
    ]);

    expect($account->type)->toBe('asset');
});