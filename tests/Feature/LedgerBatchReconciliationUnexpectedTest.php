<?php

use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Services\LedgerBatchReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('identifies ledger transactions without a matching source payment', function () {
    // Payment A: has a matching ledger transaction
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

    // Unexpected ledger transaction with no corresponding source payment
    $unexpectedTransaction = LedgerTransaction::factory()->create([
        'source_type' => PaymentAttempt::class,
        'source_id' => '00000000-0000-0000-0000-000000000000', // Non-existent source
        'amount' => 5000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
    ]);

    $reconciler = new LedgerBatchReconciler();
    $unexpected = $reconciler->findUnexpected(LedgerTransaction::whereNotNull('posted_at')->get(), PaymentAttempt::all());

    expect($unexpected)->toHaveCount(1)
        ->and($unexpected->first()->id)->toBe($unexpectedTransaction->id);
});