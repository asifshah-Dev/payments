<?php

use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Services\LedgerBatchReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('identifies amount mismatches between source and ledger', function () {
    $payment = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'USD',
    ]);

    LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => $payment->id,
        'amount' => 9500, // Mismatched ledger amount
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $summary = $reconciler->reconcileBatch(
        PaymentAttempt::all(),
        LedgerTransaction::whereNotNull('posted_at')->get()
    );

    expect($summary['mismatches'])->toHaveCount(1)
        ->and($summary['mismatches'][0]['payment']->id)->toBe($payment->id)
        ->and($summary['mismatches'][0]['source_amount'])->toBe(10000)
        ->and($summary['mismatches'][0]['ledger_amount'])->toBe(9500);
});