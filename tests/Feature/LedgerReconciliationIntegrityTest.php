<?php

use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Services\LedgerReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detects a discrepancy when the source payment amount does not match the ledger transaction amount', function () {
    $payment = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $payment->id,
        'amount' => 9000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerReconciler();
    $discrepancies = $reconciler->reconcile($payment);

    expect($discrepancies)->not->toBeEmpty()
        ->and($discrepancies[0]['type'])->toBe('amount_mismatch');
});

it('passes reconciliation when the source payment amount matches the ledger transaction amount', function () {
    $payment = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $payment->id,
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerReconciler();
    $discrepancies = $reconciler->reconcile($payment);

    expect($discrepancies)->toBeEmpty();
});