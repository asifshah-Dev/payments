<?php

use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Services\LedgerBatchReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a fully matched reconciliation summary with zero missing or unexpected items', function () {
    $paymentA = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $paymentA->id,
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $paymentB = PaymentAttempt::factory()->create([
        'amount' => 15000,
        'currency' => 'USD',
    ]);

    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $paymentB->id,
        'amount' => 15000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(
        PaymentAttempt::all(),
        LedgerTransaction::whereNotNull('posted_at')->get()
    );

    expect($summary['matched'])->toHaveCount(2)
        ->and($summary['missing'])->toBeEmpty()
        ->and($summary['unexpected'])->toBeEmpty();
});