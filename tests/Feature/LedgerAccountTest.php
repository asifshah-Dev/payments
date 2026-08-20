<?php
use App\Models\Merchant;
use App\Models\LedgerAccount;
use App\Services\LedgerAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
uses(RefreshDatabase::class);


test('ledger account belongs to a merchant', function () {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $account = LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);
    

    expect($account->merchant)
        ->toBeInstanceOf(Merchant::class)
        ->and($account->merchant->id)
        ->toBe($merchant->id);
});
test('ledger account can exist without a merchant', function () {
    $account = LedgerAccount::create([
        'merchant_id' => null,
        'name' => 'Stripe Clearing',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect($account->merchant)->toBeNull()
        ->and($account->merchant_id)->toBeNull();
});
test('merchant has many ledger accounts', function () {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $account1 = LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $account2 = LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Merchant Receivable',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect($merchant->ledgerAccounts)
        ->toHaveCount(2)
        ->and($merchant->ledgerAccounts->pluck('id'))
        ->toContain($account1->id, $account2->id);
});
test('ledger account requires a valid account type', function () {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    expect(fn () => LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Invalid Account',
        'type' => 'invalid_type',
        'currency' => 'USD',
        'status' => 'active',
    ]))->toThrow(InvalidArgumentException::class);
});
test('ledger account requires a valid account status', function () {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    expect(fn () => LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Invalid Status Account',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'invalid_status',
    ]))->toThrow(InvalidArgumentException::class);
});
test('ledger account requires a valid three letter currency', function () {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    expect(fn () => LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Invalid Currency Account',
        'type' => 'asset',
        'currency' => 'US',
        'status' => 'active',
    ]))->toThrow(InvalidArgumentException::class);
});
test('ledger account normalizes currency to uppercase', function () {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $account = LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'usd',
        'status' => 'active',
    ]);

    expect($account->currency)->toBe('USD');
});
test('merchant cannot have duplicate ledger accounts with same name type and currency', function () {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect(fn () => LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
test('different merchants can have the same ledger account', function () {
    $merchantA = Merchant::create([
        'name' => 'Merchant A',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $merchantB = Merchant::create([
        'name' => 'Merchant B',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $accountA = LedgerAccount::create([
        'merchant_id' => $merchantA->id,
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $accountB = LedgerAccount::create([
        'merchant_id' => $merchantB->id,
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect($accountA->id)->not->toBe($accountB->id);
});
test('frozen and closed ledger accounts are valid statuses', function () {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $frozen = LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Frozen Account',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'frozen',
    ]);

    $closed = LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Closed Account',
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'closed',
    ]);

    expect($frozen->status)->toBe('frozen')
        ->and($closed->status)->toBe('closed');
});
test('ledger account cannot be updated to an invalid status', function () {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $account = LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect(fn () => $account->update([
        'status' => 'invalid',
    ]))->toThrow(\InvalidArgumentException::class);
});
test('ledger account cannot be updated to an invalid account type', function () {
    $merchant = Merchant::create([
        'name' => 'Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $account = LedgerAccount::create([
        'merchant_id' => $merchant->id,
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    expect(fn () =>
        $account->fill([
            'type' => 'invalid',
        ])->save()
    )->toThrow(\InvalidArgumentException::class);
});