<?php

use App\Models\LedgerAccount;
use App\Services\LedgerAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;

uses(RefreshDatabase::class);

test('resolves debit and credit accounts for a currency', function () {
    $debit = LedgerAccount::create([
        'name' => 'USD Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $credit = LedgerAccount::create([
        'name' => 'USD Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $accounts = app(LedgerAccountResolver::class)
        ->resolve('USD');

    expect($accounts['debit']->id)
        ->toBe($debit->id);

    expect($accounts['credit']->id)
        ->toBe($credit->id);
});

test('does not resolve inactive accounts', function () {
    LedgerAccount::create([
        'name' => 'USD Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'frozen',
    ]);

    LedgerAccount::create([
        'name' => 'USD Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect(fn () =>
        app(LedgerAccountResolver::class)->resolve('USD')
    )->toThrow(RuntimeException::class);
});

test('rejects missing credit account', function () {
    LedgerAccount::create([
        'name' => 'USD Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect(fn () =>
        app(LedgerAccountResolver::class)->resolve('USD')
    )->toThrow(RuntimeException::class);
});

test('rejects missing debit account', function () {
    LedgerAccount::create([
        'name' => 'USD Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect(fn () =>
        app(LedgerAccountResolver::class)->resolve('USD')
    )->toThrow(RuntimeException::class);
});

test('rejects multiple active debit accounts', function () {
    LedgerAccount::create([
        'name' => 'USD Clearing 1',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'USD Clearing 2',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'USD Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect(fn () =>
        app(LedgerAccountResolver::class)->resolve('USD')
    )->toThrow(RuntimeException::class);
});

test('rejects multiple active credit accounts', function () {
    LedgerAccount::create([
        'name' => 'USD Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'USD Merchant Payable 1',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'USD Merchant Payable 2',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect(fn () =>
        app(LedgerAccountResolver::class)->resolve('USD')
    )->toThrow(RuntimeException::class);
});