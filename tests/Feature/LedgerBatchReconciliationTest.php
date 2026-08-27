<?php

use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Services\LedgerBatchReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('identifies source payments missing from the ledger', function () {
    // Payment A: recorded in the ledger
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

    // Payment B: missing from the ledger
    $paymentB = PaymentAttempt::factory()->create([
        'amount' => 20000,
        'currency' => 'USD',
    ]);

    $reconciler = new LedgerBatchReconciler();
    $missing = $reconciler->findMissing(PaymentAttempt::all());

    expect($missing)->toHaveCount(1)
        ->and($missing->first()->id)->toBe($paymentB->id);
});