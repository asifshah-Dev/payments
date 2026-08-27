<?php

use App\Models\LedgerTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (!Schema::hasTable('accounting_periods')) {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('open');
            $table->timestamps();
        });
    }
});

it('prevents posting a ledger transaction into a closed accounting period', function () {
    DB::table('accounting_periods')->insert([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'closed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => '2026-01-15 12:00:00',
    ]))->toThrow(InvalidArgumentException::class, 'Cannot post a transaction into a closed accounting period.');
});

it('allows posting a ledger transaction into an open accounting period', function () {
    DB::table('accounting_periods')->insert([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transaction = LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => '2026-02-15 12:00:00',
    ]);

    expect($transaction)->toBeInstanceOf(LedgerTransaction::class);
});

it('prevents modifying a transaction whose posting date falls into a closed accounting period', function () {
    DB::table('accounting_periods')->insert([
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create transaction while period is open
    $transaction = LedgerTransaction::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => '2026-03-15 12:00:00',
    ]);

    // Close the accounting period
    DB::table('accounting_periods')
        ->where('start_date', '2026-03-01')
        ->update(['status' => 'closed']);

    // Attempting to update description or attributes for a transaction in a closed period should fail
    expect(fn () => $transaction->update([
        'description' => 'Updated description',
    ]))->toThrow(InvalidArgumentException::class, 'Cannot modify a transaction within a closed accounting period.');
});