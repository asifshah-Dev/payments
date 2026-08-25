<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Services\LedgerPostingService;

it('maintains a perfect trial balance where total system debits equal total system credits across complex lifecycles', function () {
    // 1. Setup Accounts
    $merchantA = Merchant::factory()->create();
    $merchantB = Merchant::factory()->create();

    LedgerAccount::create([
        'name' => 'Platform Gateway Clearing',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Payable - ' . $merchantA->id,
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchantA->id,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Payable - ' . $merchantB->id,
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchantB->id,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Platform Fee Revenue',
        'type' => 'revenue',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    $postingService = app(LedgerPostingService::class);

    // 2. Execute a chaotic mix of financial events for both merchants

    // Merchant A: Payment (15,000) -> Payout (14,700) -> Partial Refund (3,000)
    $intentA = PaymentIntent::factory()->create(['merchant_id' => $merchantA->id, 'amount' => 15000, 'currency' => 'PKR']);
    $attemptA = PaymentAttempt::factory()->create(['payment_intent_id' => $intentA->id, 'amount' => 15000, 'currency' => 'PKR', 'status' => 'succeeded', 'fee_amount' => 300]);
    $postingService->postFromPaymentAttempt($attemptA);

    $payoutA = Payout::factory()->create(['merchant_id' => $merchantA->id, 'amount' => 14700, 'currency' => 'PKR', 'status' => 'completed']);
    $postingService->postFromPayout($payoutA);

    $postingService->postRefundFromPaymentAttempt($attemptA, 3000);

    // Merchant B: Payment (8,000) -> Chargeback after payout -> Chargeback fee
    $intentB = PaymentIntent::factory()->create(['merchant_id' => $merchantB->id, 'amount' => 8000, 'currency' => 'PKR']);
    $attemptB = PaymentAttempt::factory()->create(['payment_intent_id' => $intentB->id, 'amount' => 8000, 'currency' => 'PKR', 'status' => 'succeeded', 'fee_amount' => 160]);
    $postingService->postFromPaymentAttempt($attemptB);

    $payoutB = Payout::factory()->create(['merchant_id' => $merchantB->id, 'amount' => 7840, 'currency' => 'PKR', 'status' => 'completed']);
    $postingService->postFromPayout($payoutB);

    $postingService->postChargebackFromPaymentAttempt($attemptB, 8000);
    $postingService->postChargebackFeeFromPaymentAttempt($attemptB, 500);

    // 3. Compute Global Trial Balance
    $totalDebits = (int) LedgerEntry::where('type', 'debit')->sum('amount');
    $totalCredits = (int) LedgerEntry::where('type', 'credit')->sum('amount');

    // 4. Invariant Assertion
    expect($totalDebits)->toBeGreaterThan(0)
        ->and($totalCredits)->toBeGreaterThan(0)
        ->and($totalDebits)->toBe($totalCredits);
});