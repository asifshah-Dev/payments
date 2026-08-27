<?php

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates the account balance as the net sum of its entries', function () {
    $account = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'liability', // Merchant Payable
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 12000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => null,
    ]);

    // Credit increases liability (+10,000)
    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'credit',
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    // Debit decreases liability (-2,000)
    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 2000,
        'currency' => 'USD',
    ]);

    expect($account->balance())->toEqual(8000);
});

it('prevents adding entries with a currency different from the ledger account', function () {
    $usdAccount = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'liability',
    ]);

    $eurTx = LedgerTransaction::factory()->create([
        'amount' => 3000,
        'currency' => 'EUR',
        'direction' => 'debit',
        'posted_at' => null,
    ]);

    // Expecting the model guard to throw an InvalidArgumentException when currency mismatches account
    expect(fn () => LedgerEntry::factory()->create([
        'ledger_transaction_id' => $eurTx->id,
        'ledger_account_id' => $usdAccount->id,
        'type' => 'credit',
        'amount' => 3000,
        'currency' => 'EUR',
    ]))->toThrow(InvalidArgumentException::class, 'Ledger entry currency must match ledger account currency.');
});

it('correctly calculates balance for asset accounts versus liability accounts', function () {
    // Asset account (e.g., Cash / Bank) -> Debits increase, Credits decrease
    $assetAccount = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'asset',
    ]);

    $tx = LedgerTransaction::factory()->create([
        'amount' => 20000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => null,
    ]);

    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $tx->id,
        'ledger_account_id' => $assetAccount->id,
        'type' => 'debit',
        'amount' => 15000,
        'currency' => 'USD',
    ]);

    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $tx->id,
        'ledger_account_id' => $assetAccount->id,
        'type' => 'credit',
        'amount' => 5000,
        'currency' => 'USD',
    ]);

    // Asset balance: 15000 - 5000 = 10000
    expect($assetAccount->balance())->toEqual(10000);
});

it('handles a complex transaction history with payments, refunds, payouts, and chargebacks', function () {
    $account = LedgerAccount::factory()->create([
        'currency' => 'USD',
        'type' => 'liability', // Merchant Payable
    ]);

    $tx = LedgerTransaction::factory()->create([
        'amount' => 18000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => null,
    ]);

    // 1. Payment received: +10,000 credit
    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $tx->id,
        'ledger_account_id' => $account->id,
        'type' => 'credit',
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    // 2. Partial refund: -2,000 debit
    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $tx->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 2000,
        'currency' => 'USD',
    ]);

    // 3. Payout to merchant: -5,000 debit
    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $tx->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 5000,
        'currency' => 'USD',
    ]);

    // 4. Chargeback: -1,000 debit
    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $tx->id,
        'ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    // Net calculation for liability: Credits (10,000) - Debits (2000 + 5000 + 1000 = 8000) = 2000
    expect($account->balance())->toEqual(2000);
});