<?php

use App\Models\LedgerTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prevents moving a posted transaction back into a pending or unposted state', function () {
    $transaction = LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    expect(fn () => $transaction->update([
        'posted_at' => null,
    ]))->toThrow(InvalidArgumentException::class);
});

it('prevents moving a reversed transaction back into a posted state', function () {
    $transaction = LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
        'reversed_at' => now(),
    ]);

    expect(fn () => $transaction->update([
        'reversed_at' => null,
    ]))->toThrow(InvalidArgumentException::class);
});

it('prevents re-reversing an already reversed transaction', function () {
    $transaction = LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
        'reversed_at' => now(),
    ]);

    expect(fn () => $transaction->update([
        'reversed_at' => now()->addHour(),
    ]))->toThrow(InvalidArgumentException::class);
});

it('allows idempotent re-posting of an already posted transaction', function () {
    $postedAt = now();
    $transaction = LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => $postedAt,
    ]);

    // Re-updating posted_at to the same value should be a valid no-op
    $transaction->update([
        'posted_at' => $postedAt,
    ]);

    expect($transaction)->toBeInstanceOf(LedgerTransaction::class);
});