<?php

use App\Models\LedgerAccount;
use App\Models\LedgerAccountMapping;
use App\Services\LedgerAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Ensure a default successful_payment mapping exists for USD in these tests
    LedgerAccountMapping::firstOrCreate([
        'context' => 'successful_payment',
        'currency' => 'USD',
    ], [
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);
});

test('resolves debit and credit accounts for a currency', function () {
    $debit = LedgerAccount::create([
        'name' => 'USD Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    // Note: resolveCreditAccount requires a merchant for merchant_payable, 
    // or if your test resolver supports null merchant, ensure it matches. 
    // Let's create a dummy merchant if needed, or pass it.
    $merchant = \App\Models\Merchant::factory()->create();

    $credit = LedgerAccount::create([
        'name' => 'USD Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    // Update the test call to pass the merchant if your resolver requires it
    $accounts = app(LedgerAccountResolver::class)
        ->resolve('USD', 'successful_payment', $merchant);

    expect($accounts['debit']->id)
        ->toBe($debit->id);

    expect($accounts['credit']->id)
        ->toBe($credit->id);
});

test('does not resolve inactive accounts', function () {
    $merchant = \App\Models\Merchant::factory()->create();

    LedgerAccount::create([
        'name' => 'USD Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'merchant_id' => null,
        'status' => 'frozen',
    ]);

    LedgerAccount::create([
        'name' => 'USD Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    expect(fn () =>
        app(LedgerAccountResolver::class)->resolve('USD', 'successful_payment', $merchant)
    )->toThrow(RuntimeException::class);
});

test('rejects missing credit account', function () {
    $merchant = \App\Models\Merchant::factory()->create();

    LedgerAccount::create([
        'name' => 'USD Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    expect(fn () =>
        app(LedgerAccountResolver::class)->resolve('USD', 'successful_payment', $merchant)
    )->toThrow(RuntimeException::class);
});

test('rejects missing debit account', function () {
    $merchant = \App\Models\Merchant::factory()->create();

    LedgerAccount::create([
        'name' => 'USD Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    expect(fn () =>
        app(LedgerAccountResolver::class)->resolve('USD', 'successful_payment', $merchant)
    )->toThrow(RuntimeException::class);
});

test('rejects multiple active debit accounts', function () {
    $merchant = \App\Models\Merchant::factory()->create();

    LedgerAccount::create([
        'name' => 'USD Clearing 1',
        'type' => 'asset',
        'currency' => 'USD',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'USD Clearing 2',
        'type' => 'asset',
        'currency' => 'USD',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'USD Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    expect(fn () =>
        app(LedgerAccountResolver::class)->resolve('USD', 'successful_payment', $merchant)
    )->toThrow(RuntimeException::class);
});

test('rejects multiple active credit accounts via database constraint', function () {
    $merchant = \App\Models\Merchant::factory()->create();

    LedgerAccount::create([
        'name' => 'USD Merchant Payable 1',
        'type' => 'liability',
        'currency' => 'USD',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    expect(fn () =>
        LedgerAccount::create([
            'name' => 'USD Merchant Payable 2',
            'type' => 'liability',
            'currency' => 'USD',
            'merchant_id' => $merchant->id,
            'status' => 'active',
        ])
    )->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});