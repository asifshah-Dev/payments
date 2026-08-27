<?php

use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Services\LedgerBatchReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('identifies source payments missing from the ledger', function () {
    $paymentA = PaymentAttempt::factory()->create(['amount' => 10000, 'currency' => 'USD']);

    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $paymentA->id,
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $paymentB = PaymentAttempt::factory()->create(['amount' => 20000, 'currency' => 'USD']);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(PaymentAttempt::all(), LedgerTransaction::whereNotNull('posted_at')->get());

    expect($summary['missing'])->toHaveCount(1)
        ->and($summary['missing']->first()->id)->toBe($paymentB->id);
});

it('identifies ledger transactions without a matching source payment', function () {
    $paymentA = PaymentAttempt::factory()->create(['amount' => 10000, 'currency' => 'USD']);

    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $paymentA->id,
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $unexpectedTransaction = LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => '00000000-0000-0000-0000-000000000000',
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(PaymentAttempt::all(), LedgerTransaction::whereNotNull('posted_at')->get());

    expect($summary['unexpected'])->toHaveCount(1)
        ->and($summary['unexpected']->first()->id)->toBe($unexpectedTransaction->id);
});

it('returns matched, missing, and unexpected items in one reconciliation summary', function () {
    $paymentA = PaymentAttempt::factory()->create(['amount' => 10000, 'currency' => 'USD']);

    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $paymentA->id,
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $paymentB = PaymentAttempt::factory()->create(['amount' => 20000, 'currency' => 'USD']);

    $unexpectedTransaction = LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => '00000000-0000-0000-0000-000000000000',
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(PaymentAttempt::all(), LedgerTransaction::whereNotNull('posted_at')->get());

    expect($summary['matched'])->toHaveCount(1)
        ->and($summary['matched']->first()->id)->toBe($paymentA->id)
        ->and($summary['missing'])->toHaveCount(1)
        ->and($summary['missing']->first()->id)->toBe($paymentB->id)
        ->and($summary['unexpected'])->toHaveCount(1)
        ->and($summary['unexpected']->first()->id)->toBe($unexpectedTransaction->id);
});

it('returns a fully matched reconciliation summary with zero missing or unexpected items', function () {
    $paymentA = PaymentAttempt::factory()->create(['amount' => 10000, 'currency' => 'USD']);
    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class, 'source_id' => $paymentA->id,
        'amount' => 10000, 'currency' => 'USD', 'direction' => 'debit', 'posted_at' => now(),
    ]);

    $paymentB = PaymentAttempt::factory()->create(['amount' => 15000, 'currency' => 'USD']);
    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class, 'source_id' => $paymentB->id,
        'amount' => 15000, 'currency' => 'USD', 'direction' => 'debit', 'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(PaymentAttempt::all(), LedgerTransaction::whereNotNull('posted_at')->get());

    expect($summary['matched'])->toHaveCount(2)
        ->and($summary['missing'])->toBeEmpty()
        ->and($summary['unexpected'])->toBeEmpty();
});

it('identifies amount mismatches between source and ledger', function () {
    $payment = PaymentAttempt::factory()->create(['amount' => 10000, 'currency' => 'USD']);

    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $payment->id,
        'amount' => 9500,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(PaymentAttempt::all(), LedgerTransaction::whereNotNull('posted_at')->get());

    expect($summary['mismatches'])->toHaveCount(1)
        ->and($summary['mismatches'][0]['payment']->id)->toBe($payment->id)
        ->and($summary['mismatches'][0]['source_amount'])->toBe(10000)
        ->and($summary['mismatches'][0]['ledger_amount'])->toBe(9500);
});

it('identifies currency mismatches between source and ledger', function () {
    $payment = PaymentAttempt::factory()->create(['amount' => 10000, 'currency' => 'USD']);

    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $payment->id,
        'amount' => 10000,
        'currency' => 'PKR', // Different currency
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(PaymentAttempt::all(), LedgerTransaction::whereNotNull('posted_at')->get());

    expect($summary['currency_mismatches'])->toHaveCount(1)
        ->and($summary['currency_mismatches'][0]['source_currency'])->toBe('USD')
        ->and($summary['currency_mismatches'][0]['ledger_currency'])->toBe('PKR');
});

it('detects duplicate ledger transactions for the same source payment', function () {
    $payment = PaymentAttempt::factory()->create(['amount' => 10000, 'currency' => 'USD']);

    // First transaction
    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $payment->id,
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    // Duplicate transaction
    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $payment->id,
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(PaymentAttempt::all(), LedgerTransaction::whereNotNull('posted_at')->get());

    expect($summary['duplicates'])->toHaveCount(1)
        ->and($summary['duplicates'][0]['payment_id'])->toBe($payment->id);
});

it('calculates reconciliation totals separately for each currency', function () {
    $paymentUsd = PaymentAttempt::factory()->create(['amount' => 10000, 'currency' => 'USD']);
    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class, 'source_id' => $paymentUsd->id,
        'amount' => 10000, 'currency' => 'USD', 'direction' => 'debit', 'posted_at' => now(),
    ]);

    $paymentPkr = PaymentAttempt::factory()->create(['amount' => 50000, 'currency' => 'PKR']);
    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class, 'source_id' => $paymentPkr->id,
        'amount' => 50000, 'currency' => 'PKR', 'direction' => 'debit', 'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(PaymentAttempt::all(), LedgerTransaction::whereNotNull('posted_at')->get());

    expect($summary['totals']['USD']['source'])->toBe(10000)
        ->and($summary['totals']['USD']['ledger'])->toBe(10000)
        ->and($summary['totals']['PKR']['source'])->toBe(50000)
        ->and($summary['totals']['PKR']['ledger'])->toBe(50000);
});

it('marks reconciliation as reconciled only when all items match cleanly', function () {
    $payment = PaymentAttempt::factory()->create(['amount' => 10000, 'currency' => 'USD']);
    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class, 'source_id' => $payment->id,
        'amount' => 10000, 'currency' => 'USD', 'direction' => 'debit', 'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(PaymentAttempt::all(), LedgerTransaction::whereNotNull('posted_at')->get());

    expect($summary['status'])->toBe('reconciled');
});

it('marks reconciliation as discrepancy when any anomaly is present', function () {
    // Missing payment case
    PaymentAttempt::factory()->create(['amount' => 10000, 'currency' => 'USD']);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(PaymentAttempt::all(), LedgerTransaction::whereNotNull('posted_at')->get());

    expect($summary['status'])->toBe('discrepancy');
});