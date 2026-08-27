<?php

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prevents posting ledger entries across different merchant account boundaries in a single transaction', function () {
    $merchantA = Merchant::factory()->create();
    $merchantB = Merchant::factory()->create();

    $accountA = LedgerAccount::factory()->create([
        'merchant_id' => $merchantA->id,
        'currency' => 'USD',
        'type' => 'liability',
    ]);

    $accountB = LedgerAccount::factory()->create([
        'merchant_id' => $merchantB->id,
        'currency' => 'USD',
        'type' => 'liability',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => null,
    ]);

    // First entry belongs to Merchant A's account
    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $accountA->id,
        'type' => 'credit',
        'amount' => 5000,
        'currency' => 'USD',
    ]);

    // Attempting to add an entry for Merchant B's account to the same transaction must fail
    expect(fn () => LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $accountB->id,
        'type' => 'debit',
        'amount' => 5000,
        'currency' => 'USD',
    ]))->toThrow(InvalidArgumentException::class, 'Ledger entry cannot mix accounts from different merchants in a single transaction.');
});

it('prevents posting to an account whose merchant does not match the transaction source merchant', function () {
    $merchantA = Merchant::factory()->create();
    $merchantB = Merchant::factory()->create();

    $accountA = LedgerAccount::factory()->create([
        'merchant_id' => $merchantA->id,
        'currency' => 'USD',
        'type' => 'liability',
    ]);

    $paymentIntent = PaymentIntent::factory()->create([
        'merchant_id' => $merchantB->id,
    ]);

    $paymentAttempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'amount' => 5000,
        'currency' => 'USD',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => null,
        'source_type' => get_class($paymentAttempt),
        'source_id' => $paymentAttempt->id,
    ]);

    // Attempting to post Merchant B's transaction into Merchant A's account must fail
    expect(fn () => LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $accountA->id,
        'type' => 'credit',
        'amount' => 5000,
        'currency' => 'USD',
    ]))->toThrow(InvalidArgumentException::class, 'Ledger entry account merchant does not match transaction source merchant.');
});

it('respects currency and merchant ownership together', function () {
    $merchantA = Merchant::factory()->create();

    $accountUSD = LedgerAccount::factory()->create([
        'merchant_id' => $merchantA->id,
        'currency' => 'USD',
        'type' => 'liability',
    ]);

    $transactionEUR = LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'EUR',
        'direction' => 'debit',
        'posted_at' => null,
    ]);

    // Currency mismatch between account and transaction/entry fails validation
    expect(fn () => LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transactionEUR->id,
        'ledger_account_id' => $accountUSD->id,
        'type' => 'credit',
        'amount' => 5000,
        'currency' => 'EUR',
    ]))->toThrow(InvalidArgumentException::class);
});

it('allows platform accounts with null merchant id alongside merchant operations', function () {
    $merchantA = Merchant::factory()->create();

    // Platform account (merchant_id is null)
    $platformAccount = LedgerAccount::factory()->create([
        'merchant_id' => null,
        'currency' => 'USD',
        'type' => 'asset',
    ]);

    $merchantAccount = LedgerAccount::factory()->create([
        'merchant_id' => $merchantA->id,
        'currency' => 'USD',
        'type' => 'liability',
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => null,
    ]);

    // Platform account entry
    LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $platformAccount->id,
        'type' => 'debit',
        'amount' => 5000,
        'currency' => 'USD',
    ]);

    // Merchant account entry should succeed because platform account has no conflicting merchant ID
    $merchantEntry = LedgerEntry::factory()->create([
        'ledger_transaction_id' => $transaction->id,
        'ledger_account_id' => $merchantAccount->id,
        'type' => 'credit',
        'amount' => 5000,
        'currency' => 'USD',
    ]);

    expect($merchantEntry)->toBeInstanceOf(LedgerEntry::class);
});