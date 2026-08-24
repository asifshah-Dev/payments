<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccountMapping;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

it('requires a context', function () {
    LedgerAccountMapping::create([
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);
})->throws(InvalidArgumentException::class);

it('allows successful_payment as a valid context', function () {
    $mapping = LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);

    expect($mapping->context)->toBe('successful_payment');
});

it('rejects an invalid context', function () {
    LedgerAccountMapping::create([
        'context' => 'invalid_context',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);
})->throws(InvalidArgumentException::class);

it('allows platform_gateway_clearing as a valid debit role', function () {
    $mapping = LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);

    expect($mapping->debit_account_role)->toBe('platform_gateway_clearing');
});

it('allows merchant_payable as a valid credit role', function () {
    $mapping = LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);

    expect($mapping->credit_account_role)->toBe('merchant_payable');
});

it('rejects an invalid debit role', function () {
    LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'invalid_debit_role',
        'credit_account_role' => 'merchant_payable',
    ]);
})->throws(InvalidArgumentException::class);

it('rejects an invalid credit role', function () {
    LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'invalid_credit_role',
    ]);
})->throws(InvalidArgumentException::class);

it('ensures successful payment mapping has the expected debit and credit roles', function () {
    $mapping = LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);

    expect($mapping->debit_account_role)->toBe('platform_gateway_clearing')
        ->and($mapping->credit_account_role)->toBe('merchant_payable');
});

it('prevents duplicate mapping for the same context and currency', function () {
    LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);

    LedgerAccountMapping::create([
        'context' => 'successful_payment',
        'currency' => 'PKR',
        'debit_account_role' => 'platform_gateway_clearing',
        'credit_account_role' => 'merchant_payable',
    ]);
})->throws(QueryException::class);