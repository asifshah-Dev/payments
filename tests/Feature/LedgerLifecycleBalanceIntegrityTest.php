<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Services\PaymentRecoveryManager;

beforeEach(function () {
    Schema::disableForeignKeyConstraints();

    Schema::dropIfExists('recovery_ledger_transactions');
    Schema::dropIfExists('recovery_payments');

    Schema::enableForeignKeyConstraints();

    Schema::create('recovery_payments', function (Blueprint $table) {
        $table->engine = 'InnoDB';
        $table->id();
        $table->unsignedBigInteger('merchant_id')->default(1);
        $table->string('status')->default('pending');
        $table->unsignedBigInteger('amount');
        $table->string('currency');
        $table->timestamps();
    });

    Schema::create('recovery_ledger_transactions', function (Blueprint $table) {
        $table->engine = 'InnoDB';
        $table->id();
        $table->unsignedBigInteger('payment_id');
        $table->unsignedBigInteger('merchant_id')->default(1);
        $table->unsignedBigInteger('amount');
        $table->string('currency');
        $table->string('direction')->default('debit');
        $table->string('type')->default('capture');
        $table->timestamp('posted_at')->nullable();
        $table->timestamps();
    });
});

it('verifies balance after payment and refund', function () {
    $merchantId = 1;
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId,
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->postRefund($paymentId, 3000);

    expect($manager->calculateBalance($merchantId, 'USD'))->toBe(7000);
});

it('verifies balance after multiple partial refunds', function () {
    $merchantId = 1;
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId,
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->postRefund($paymentId, 2000);
    $manager->postRefund($paymentId, 3000);

    expect($manager->calculateBalance($merchantId, 'USD'))->toBe(5000);
});

it('verifies balance after payment, fee, and payout', function () {
    $merchantId = 1;
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId,
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false); // +10000
    $manager->postLedgerEntry($paymentId, 'fee', 'credit', 500, 'USD', $merchantId); // -500
    $manager->postLedgerEntry($paymentId, 'payout', 'credit', 4000, 'USD', $merchantId); // -4000

    expect($manager->calculateBalance($merchantId, 'USD'))->toBe(5500);
});

it('verifies balance changes correctly after chargeback', function () {
    $merchantId = 1;
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId,
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->postChargeback($paymentId);

    expect($manager->calculateBalance($merchantId, 'USD'))->toBe(0);
});

it('restores original balance after chargeback reversal', function () {
    $merchantId = 1;
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId,
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->postChargeback($paymentId);
    $manager->postChargebackReversal($paymentId);

    expect($manager->calculateBalance($merchantId, 'USD'))->toBe(10000);
});

it('handles refund and chargeback on different payments independently', function () {
    $merchantId = 1;
    $pay1 = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId, 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $pay2 = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId, 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($pay1, false);
    $manager->attemptPosting($pay2, false);
    $manager->postRefund($pay1, 2000);
    $manager->postChargeback($pay2);

    expect($manager->calculateBalance($merchantId, 'USD'))->toBe(3000);
});

it('supports multiple currencies without cross-contamination', function () {
    $merchantId = 1;
    $payUSD = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId, 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $payEUR = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId, 'status' => 'succeeded', 'amount' => 8000, 'currency' => 'EUR', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($payUSD, false);
    $manager->attemptPosting($payEUR, false);

    expect($manager->calculateBalance($merchantId, 'USD'))->toBe(10000);
    expect($manager->calculateBalance($merchantId, 'EUR'))->toBe(8000);
});

it('ensures multiple merchants do not affect each others balances', function () {
    $m1 = 1;
    $m2 = 2;
    $pay1 = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $m1, 'status' => 'succeeded', 'amount' => 15000, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $pay2 = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $m2, 'status' => 'succeeded', 'amount' => 25000, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($pay1, false);
    $manager->attemptPosting($pay2, false);

    expect($manager->calculateBalance($m1, 'USD'))->toBe(15000);
    expect($manager->calculateBalance($m2, 'USD'))->toBe(25000);
});

it('keeps final balance invariant when reprocessing entire lifecycle events', function () {
    $merchantId = 1;
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId, 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->attemptPosting($paymentId, false); // Reprocess duplicate attempt
    $manager->postRefund($paymentId, 2000);

    expect($manager->calculateBalance($merchantId, 'USD'))->toBe(8000);
});

it('does not mutate historical entries when subsequent adjustments occur', function () {
    $merchantId = 1;
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId, 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);

    $captureTx = DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->where('type', 'capture')->first();
    $originalAmount = $captureTx->amount;

    $manager->postRefund($paymentId, 4000);

    $refreshedCapture = DB::table('recovery_ledger_transactions')->where('id', $captureTx->id)->first();
    expect((int) $refreshedCapture->amount)->toBe((int) $originalAmount);
});

it('maintains closed accounting period balance unchanged', function () {
    $merchantId = 1;
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId, 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);

    // Simulate snapshot of closed period balance
    $closedPeriodBalance = $manager->calculateBalance($merchantId, 'USD');

    // Subsequent activity
    $manager->postRefund($paymentId, 3000);

    expect($closedPeriodBalance)->toBe(10000);
    expect($manager->calculateBalance($merchantId, 'USD'))->toBe(7000);
});

it('matches final ledger balance with independently calculated comprehensive lifecycle expectation', function () {
    $merchantId = 1;
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'merchant_id' => $merchantId, 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();

    // Lifecycle: $10,000 payment -> capture -> -$2,000 partial refund -> -$1,000 fee -> $3,000 payout -> $500 chargeback -> $500 chargeback reversal
    // Note: A partial refund sets status to 'partial_refunded', which is now explicitly permitted for chargebacks.
    $manager->attemptPosting($paymentId, false);                             // +10000
    $manager->postRefund($paymentId, 2000);                                  // -2000 (status becomes partial_refunded)
    $manager->postLedgerEntry($paymentId, 'fee', 'credit', 1000, 'USD', $merchantId);         // -1000
    $manager->postLedgerEntry($paymentId, 'payout', 'debit', 3000, 'USD', $merchantId);        // +3000
    $manager->postChargeback($paymentId);                                    // -10000 (chargeback posts full payment amount credit)
    $manager->postChargebackReversal($paymentId);                            // +10000 (reversal debits it back)

    // Expected calculation: 10000 - 2000 - 1000 + 3000 - 10000 + 10000 = 10000
    $expectedBalance = 10000 - 2000 - 1000 + 3000 - 10000 + 10000;

    expect($manager->calculateBalance($merchantId, 'USD'))->toBe($expectedBalance);
});