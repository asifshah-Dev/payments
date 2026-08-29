<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Services\PaymentRecoveryManager;

// No RefreshDatabase for this isolated recovery test.

beforeEach(function () {
    Schema::disableForeignKeyConstraints();

    Schema::dropIfExists('recovery_ledger_transactions');
    Schema::dropIfExists('recovery_payments');

    Schema::enableForeignKeyConstraints();

    Schema::create('recovery_payments', function (Blueprint $table) {
        $table->engine = 'InnoDB';
        $table->id();
        $table->string('status')->default('pending');
        $table->unsignedBigInteger('amount');
        $table->string('currency');
        $table->timestamps();
    });

    Schema::create('recovery_ledger_transactions', function (Blueprint $table) {
        $table->engine = 'InnoDB';
        $table->id();
        $table->unsignedBigInteger('payment_id')->unique();
        $table->unsignedBigInteger('amount');
        $table->string('currency');
        $table->string('direction')->default('debit');
        $table->timestamp('posted_at')->nullable();
        $table->timestamps();
    });
});

it('allows a failed ledger posting to be retried successfully', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 1000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $result = $manager->attemptPosting($paymentId, true);

    expect($result)->toBeFalse();
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->exists())->toBeFalse();

    $retryResult = $manager->attemptPosting($paymentId, false);
    expect($retryResult)->toBeTrue();
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->exists())->toBeTrue();
});

it('ensures retrying ledger posting is idempotent and does not create duplicate ledger transactions', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 2000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();

    $first = $manager->attemptPosting($paymentId, false);
    $second = $manager->attemptPosting($paymentId, false);

    expect($first)->toBeTrue();
    expect($second)->toBeTrue();
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->count())->toBe(1);
});

it('rolls back database changes atomically when ledger posting fails midway', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();

    try {
        $manager->postAtomicallyWithForcedFailure($paymentId);
    } catch (\Throwable $e) {
        // Expected failure.
    }

    expect(
        DB::table('recovery_ledger_transactions')
            ->where('payment_id', $paymentId)
            ->exists()
    )->toBeFalse();
});

it('identifies stuck payments for recovery', function () {
    $stuckPaymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'stuck',
        'amount' => 3000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('recovery_payments')->insert([
        'status' => 'succeeded',
        'amount' => 4000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $stuckPayments = $manager->getStuckPayments();

    expect($stuckPayments)->toHaveCount(1);
    expect($stuckPayments->first()->id)->toBe($stuckPaymentId);
});

it('safely completes a missing posting during recovery', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'stuck',
        'amount' => 2500,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $recovered = $manager->recoverStuckPayment($paymentId);

    expect($recovered)->toBeTrue();
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->exists())->toBeTrue();
    expect(DB::table('recovery_payments')->where('id', $paymentId)->first()->status)->toBe('succeeded');
});

it('handles out of order payment events gracefully', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'pending',
        'amount' => 1500,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $result = $manager->handleOutOfOrderPosting($paymentId);

    expect($result)->toBeFalse();
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->exists())->toBeFalse();
});

it('allows delayed ledger postings to be reconciled', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'stuck',
        'amount' => 3500,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $processed = $manager->processLatePosting($paymentId);

    expect($processed)->toBeTrue();
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->exists())->toBeTrue();
});

it('executes the full payment failure retry and recovery lifecycle', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'stuck',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    
    expect($manager->attemptPosting($paymentId, false))->toBeFalse();
    expect($manager->processLatePosting($paymentId))->toBeTrue();
    expect($manager->attemptPosting($paymentId, false))->toBeTrue();
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->count())->toBe(1);
});