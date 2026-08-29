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
        $table->string('status')->default('pending');
        $table->unsignedBigInteger('amount');
        $table->string('currency');
        $table->timestamps();
    });

    Schema::create('recovery_ledger_transactions', function (Blueprint $table) {
        $table->engine = 'InnoDB';
        $table->id();
        $table->unsignedBigInteger('payment_id');
        $table->unsignedBigInteger('amount');
        $table->string('currency');
        $table->string('direction')->default('debit');
        $table->string('type')->default('capture');
        $table->timestamp('posted_at')->nullable();
        $table->timestamps();
    });
});

it('ensures pending payment has no ledger transaction', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'pending',
        'amount' => 5000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $posted = $manager->attemptPosting($paymentId, false);

    expect($posted)->toBeFalse();
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->exists())->toBeFalse();
});

it('ensures succeeded payment has exactly one capture ledger transaction', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 5000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->attemptPosting($paymentId, false); // Idempotency check

    expect(
        DB::table('recovery_ledger_transactions')
            ->where('payment_id', $paymentId)
            ->where('type', 'capture')
            ->count()
    )->toBe(1);
});

it('ensures failed payment has no capture ledger transaction', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'failed',
        'amount' => 5000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $posted = $manager->attemptPosting($paymentId, false);

    expect($posted)->toBeFalse();
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->exists())->toBeFalse();
});

it('creates corresponding refund ledger transaction for refunded payment', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->postRefund($paymentId, 10000);

    $refundTx = DB::table('recovery_ledger_transactions')
        ->where('payment_id', $paymentId)
        ->where('type', 'refund')
        ->first();

    expect($refundTx)->not->toBeNull();
    expect((int) $refundTx->amount)->toBe(10000);
    expect($refundTx->direction)->toBe('credit');
    expect(DB::table('recovery_payments')->where('id', $paymentId)->first()->status)->toBe('refunded');
});

it('ensures partial refund ledger amount equals refund amount', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->postRefund($paymentId, 4000);

    $refundTx = DB::table('recovery_ledger_transactions')
        ->where('payment_id', $paymentId)
        ->where('type', 'refund')
        ->first();

    expect((int) $refundTx->amount)->toBe(4000);
    expect(DB::table('recovery_payments')->where('id', $paymentId)->first()->status)->toBe('partial_refunded');
});

it('ensures full refund total equals original payment', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->postRefund($paymentId, 3000);
    $manager->postRefund($paymentId, 7000);

    $totalRefunded = (int) DB::table('recovery_ledger_transactions')
        ->where('payment_id', $paymentId)
        ->where('type', 'refund')
        ->sum('amount');

    expect($totalRefunded)->toBe(10000);
    expect(DB::table('recovery_payments')->where('id', $paymentId)->first()->status)->toBe('refunded');
});

it('creates corresponding chargeback ledger transaction', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 8000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->postChargeback($paymentId);

    $cbTx = DB::table('recovery_ledger_transactions')
        ->where('payment_id', $paymentId)
        ->where('type', 'chargeback')
        ->first();

    expect($cbTx)->not->toBeNull();
    expect((int) $cbTx->amount)->toBe(8000);
    expect(DB::table('recovery_payments')->where('id', $paymentId)->first()->status)->toBe('chargeback');
});

it('ensures chargeback reversal ledger transaction exists', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 8000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->postChargeback($paymentId);
    $manager->postChargebackReversal($paymentId);

    $revTx = DB::table('recovery_ledger_transactions')
        ->where('payment_id', $paymentId)
        ->where('type', 'chargeback_reversal')
        ->first();

    expect($revTx)->not->toBeNull();
    expect((int) $revTx->amount)->toBe(8000);
    expect(DB::table('recovery_payments')->where('id', $paymentId)->first()->status)->toBe('succeeded');
});

it('prevents payment from being marked succeeded while required ledger posting is missing', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'pending',
        'amount' => 5000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();

    expect(fn () => $manager->markSucceededAtomically($paymentId))
        ->toThrow(RuntimeException::class);
});

it('rejects ledger representation with incorrect amount for successful payment', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 5000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('recovery_ledger_transactions')->insert([
        'payment_id' => $paymentId,
        'amount' => 4000,
        'currency' => 'USD',
        'direction' => 'debit',
        'type' => 'capture',
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    expect($manager->validateConsistency($paymentId))->toBeFalse();
});

it('keeps repeated state processing idempotent', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 6000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();
    $manager->attemptPosting($paymentId, false);
    $manager->attemptPosting($paymentId, false);
    $manager->attemptPosting($paymentId, false);

    expect(
        DB::table('recovery_ledger_transactions')
            ->where('payment_id', $paymentId)
            ->where('type', 'capture')
            ->count()
    )->toBe(1);
});

it('rejects ledger state transition for invalid payment', function () {
    $manager = new PaymentRecoveryManager();

    expect(fn () => $manager->postRefund(99999, 1000))
        ->toThrow(InvalidArgumentException::class);
});