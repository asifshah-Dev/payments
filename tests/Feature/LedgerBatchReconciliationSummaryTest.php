<?php

use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Services\LedgerBatchReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns matched, missing, and unexpected items in one reconciliation summary', function () {
    // 1. Matched: Payment A and its ledger transaction
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

    // 2. Missing: Payment B exists in source, but has no ledger transaction
    $paymentB = PaymentAttempt::factory()->create([
        'amount' => 20000,
        'currency' => 'USD',
    ]);

    // 3. Unexpected: Ledger transaction exists, but has no matching source payment
    $unexpectedTransaction = LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => '00000000-0000-0000-0000-000000000000',
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(
        PaymentAttempt::all(),
        LedgerTransaction::whereNotNull('posted_at')->get()
    );

    expect($summary['matched'])->toHaveCount(1)
        ->and($summary['matched']->first()->id)->toBe($paymentA->id)
        ->and($summary['missing'])->toHaveCount(1)
        ->and($summary['missing']->first()->id)->toBe($paymentB->id)
        ->and($summary['unexpected'])->toHaveCount(1)
        ->and($summary['unexpected']->first()->id)->toBe($unexpectedTransaction->id);
});