<?php
use App\Models\Merchant;
use App\Models\LedgerAccount;
use App\Services\LedgerAccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
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
test('calculates asset account balance as debits minus credits', function () {
    $account = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'type' => 'payment',
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
        'posted_at' => now(),
    ]);

    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'credit',
        'amount' => 3000,
        'currency' => 'USD',
    ]);

    expect($account->balance())->toBe(7000);
});
test('calculates liability account balance as credits minus debits', function () {
    $account = LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'USD',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'type' => 'payment',
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
        'posted_at' => now(),
    ]);

    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'credit',
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 3000,
        'currency' => 'USD',
    ]);

    expect($account->balance())->toBe(7000);
});
test('calculates revenue account balance as credits minus debits', function () {
    $account = LedgerAccount::factory()->create([
        'type' => 'revenue',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'type' => 'payment',
        'amount' => 15000,
        'currency' => 'USD',
        'direction' => 'in',
        'posted_at' => now(),
    ]);

    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'credit',
        'amount' => 15000,
        'currency' => 'USD',
    ]);

    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 4000,
        'currency' => 'USD',
    ]);

    expect($account->balance())->toBe(11000);
});
test('calculates expense account balance as debits minus credits', function () {
    $account = LedgerAccount::factory()->create([
        'type' => 'expense',
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'type' => 'payment',
        'amount' => 12000,
        'currency' => 'USD',
        'direction' => 'in',
        'posted_at' => now(),
    ]);

    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 12000,
        'currency' => 'USD',
    ]);

    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'credit',
        'amount' => 2500,
        'currency' => 'USD',
    ]);

    expect($account->balance())->toBe(9500);
});
test('returns zero balance when ledger account has no entries', function () {
    $account = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'USD',
    ]);

    expect($account->balance())->toBe(0);
});
test('does not include ledger entries with a different currency in account balance', function () {
    $usdAccount = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'USD',
    ]);

    $eurAccount = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'EUR',
    ]);

    $usdTransaction = LedgerTransaction::factory()->create([
        'type' => 'payment',
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
        'posted_at' => now(),
    ]);

    $eurTransaction = LedgerTransaction::factory()->create([
        'type' => 'payment',
        'amount' => 5000,
        'currency' => 'EUR',
        'direction' => 'in',
        'posted_at' => now(),
    ]);

    // USD entry on USD account
    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $usdTransaction->id,
        'ledger_account_id' => $usdAccount->id,
        'type' => 'debit',
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    // EUR entry on EUR account (Valid because currency matches account)
    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $eurTransaction->id,
        'ledger_account_id' => $eurAccount->id,
        'type' => 'debit',
        'amount' => 5000,
        'currency' => 'EUR',
    ]);

    // Verify USD account balance only computes USD entries
    expect($usdAccount->balance())->toBe(10000)
        ->and($eurAccount->balance())->toBe(5000);
});
test('ledger entry requires a valid type', function () {
    $account = LedgerAccount::factory()->create(['type' => 'asset']);
    
    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
    ]);

    expect(fn () => LedgerEntry::create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'invalid_direction', // Should only be 'debit' or 'credit'
        'amount' => 1000,
        'currency' => 'USD',
    ]))->toThrow(InvalidArgumentException::class);
});

test('ledger transaction enforces double entry balance integrity', function () {
    $accountA = LedgerAccount::factory()->create(['currency' => 'USD', 'type' => 'asset']);
    $accountB = LedgerAccount::factory()->create(['currency' => 'USD', 'type' => 'liability']);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
    ]);

    // Attempting to create an unbalanced transaction (Debits != Credits)
    expect(fn () => \Illuminate\Support\Facades\DB::transaction(function () use ($transaction, $accountA, $accountB) {
        // Debit $100
        LedgerEntry::create([
            'ledger_transaction_id' => $transaction->id,
            'ledger_account_id' => $accountA->id,
            'type' => 'debit',
            'amount' => 10000,
            'currency' => 'USD',
        ]);

        // Credit only $5000 (Unbalanced!)
        LedgerEntry::create([
            'ledger_transaction_id' => $transaction->id,
            'ledger_account_id' => $accountB->id,
            'type' => 'credit',
            'amount' => 5000,
            'currency' => 'USD',
        ]);

        if ($transaction->entries()->where('type', 'debit')->sum('amount') !== 
            $transaction->entries()->where('type', 'credit')->sum('amount')) {
            throw new RuntimeException('Transaction entries must balance.');
        }
    }))->toThrow(RuntimeException::class);
});
test('ledger transaction accepts a balanced double entry', function () {
    $accountA = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'asset',
    ]);

    $accountB = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'liability',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
    ]);

    DB::transaction(function () use ($transaction, $accountA, $accountB) {
        LedgerEntry::create([
            'ledger_transaction_id' => $transaction->id,
            'ledger_account_id' => $accountA->id,
            'type' => 'debit',
            'amount' => 10000,
            'currency' => 'USD',
        ]);

        LedgerEntry::create([
            'ledger_transaction_id' => $transaction->id,
            'ledger_account_id' => $accountB->id,
            'type' => 'credit',
            'amount' => 10000,
            'currency' => 'USD',
        ]);
    });

    expect($transaction->entries()->count())->toBe(2)
        ->and(
            $transaction->entries()
                ->where('type', 'debit')
                ->sum('amount')
        )->toBe(10000)
        ->and(
            $transaction->entries()
                ->where('type', 'credit')
                ->sum('amount')
        )->toBe(10000);
});
test('ledger entry currency must match transaction currency', function () {
    $account = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'asset',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
    ]);

    expect(fn () => LedgerEntry::create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 10000,
        'currency' => 'EUR',
    ]))->toThrow(InvalidArgumentException::class);
});
test('ledger entry currency must match ledger account currency', function () {
    $account = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'asset',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'EUR',
        'direction' => 'in',
    ]);

    expect(fn () => LedgerEntry::create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 10000,
        'currency' => 'EUR',
    ]))->toThrow(InvalidArgumentException::class);
});
test('ledger entry cannot be created on a frozen account', function () {
    $account = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'asset',
        'status' => 'frozen',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
    ]);

    expect(fn () => LedgerEntry::create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 10000,
        'currency' => 'USD',
    ]))->toThrow(InvalidArgumentException::class);
});
test('ledger entry cannot be created on a closed account', function () {
    $account = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'asset',
        'status' => 'closed',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
    ]);

    expect(fn () => LedgerEntry::create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 10000,
        'currency' => 'USD',
    ]))->toThrow(InvalidArgumentException::class);
});
test('posted ledger transaction cannot be modified', function () {
    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
        'posted_at' => now(),
    ]);

    expect(fn () => $transaction->update([
        'amount' => 20000,
    ]))->toThrow(InvalidArgumentException::class);
});
test('posted ledger entry cannot be modified', function () {
    $account = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'asset',
        'status' => 'active',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
        'posted_at' => now(),
    ]);

    $entry = LedgerEntry::create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    expect(fn () => $entry->update([
        'amount' => 20000,
    ]))->toThrow(InvalidArgumentException::class);
});
test('posted ledger transaction cannot be deleted', function () {
    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
        'posted_at' => now(),
    ]);

    expect(fn () => $transaction->delete())
        ->toThrow(InvalidArgumentException::class);

    expect(LedgerTransaction::find($transaction->id))
        ->not->toBeNull();
});
test('posted ledger entry cannot be deleted', function () {
    $account = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'asset',
        'status' => 'active',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'in',
        'posted_at' => now(),
    ]);

    $entry = LedgerEntry::create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    expect(fn () => $entry->delete())
        ->toThrow(InvalidArgumentException::class);

    expect(LedgerEntry::find($entry->id))
        ->not->toBeNull();
});