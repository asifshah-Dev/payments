<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Services\PaymentRecoveryManager;

beforeEach(function () {
    Schema::disableForeignKeyConstraints();

    Schema::dropIfExists('recovery_ledger_transactions');
    Schema::dropIfExists('recovery_payments');
    Schema::dropIfExists('payment_intents');

    Schema::enableForeignKeyConstraints();

    Schema::create('payment_intents', function (Blueprint $table) {
        $table->engine = 'InnoDB';
        $table->id();
        $table->unsignedBigInteger('merchant_id')->default(1);
        $table->string('status')->default('pending');
        $table->unsignedBigInteger('amount');
        $table->string('currency');
        $table->unsignedInteger('attempts_count')->default(0);
        $table->timestamps();
    });

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

it('starts payment intent in pending or created status', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent([
        'merchant_id' => 1,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    $intent = DB::table('payment_intents')->where('id', $intentId)->first();
    expect($intent->status)->toBe('pending');
});

it('allows valid transition from pending to processing', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'processing');
    $intent = DB::table('payment_intents')->where('id', $intentId)->first();

    expect($intent->status)->toBe('processing');
});

it('transitions from processing to succeeded successfully', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'processing');
    $manager->updatePaymentIntentStatus($intentId, 'succeeded');

    $intent = DB::table('payment_intents')->where('id', $intentId)->first();
    expect($intent->status)->toBe('succeeded');
});

it('transitions from processing to failed successfully', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'processing');
    $manager->updatePaymentIntentStatus($intentId, 'failed');

    $intent = DB::table('payment_intents')->where('id', $intentId)->first();
    expect($intent->status)->toBe('failed');
});

it('rejects direct transition from pending to succeeded if processing is required', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    expect(fn() => $manager->updatePaymentIntentStatus($intentId, 'succeeded'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects transition from succeeded to pending', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'processing');
    $manager->updatePaymentIntentStatus($intentId, 'succeeded');

    expect(fn() => $manager->updatePaymentIntentStatus($intentId, 'pending'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects transition from succeeded to failed', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'processing');
    $manager->updatePaymentIntentStatus($intentId, 'succeeded');

    expect(fn() => $manager->updatePaymentIntentStatus($intentId, 'failed'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects transition from succeeded to cancelled', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'processing');
    $manager->updatePaymentIntentStatus($intentId, 'succeeded');

    expect(fn() => $manager->updatePaymentIntentStatus($intentId, 'cancelled'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects failed to succeeded unless business rules explicitly allow retry', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'processing');
    $manager->updatePaymentIntentStatus($intentId, 'failed');

    // Our rule allows failed -> processing (retry), but not failed -> succeeded directly
    expect(fn() => $manager->updatePaymentIntentStatus($intentId, 'succeeded'))
        ->toThrow(InvalidArgumentException::class);

    // Retry path: failed -> processing -> succeeded
    $manager->updatePaymentIntentStatus($intentId, 'processing');
    $manager->updatePaymentIntentStatus($intentId, 'succeeded');
    expect(DB::table('payment_intents')->where('id', $intentId)->value('status'))->toBe('succeeded');
});

it('prevents cancelled payment intent from being processed', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'cancelled');

    expect(fn() => $manager->updatePaymentIntentStatus($intentId, 'processing'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects invalid state transitions consistently', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    expect(fn() => $manager->updatePaymentIntentStatus($intentId, 'random_state'))
        ->toThrow(InvalidArgumentException::class);
});

it('handles repeating the same valid transition idempotently', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'processing');
    // Repeating processing again should be safe/idempotent
    $manager->updatePaymentIntentStatus($intentId, 'processing');

    expect(DB::table('payment_intents')->where('id', $intentId)->value('status'))->toBe('processing');
});

it('prevents payment amount and currency from changing after processing begins', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'processing');

    expect(fn() => $manager->updateIntentDetails($intentId, ['amount' => 6000]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn() => $manager->updateIntentDetails($intentId, ['currency' => 'EUR']))
        ->toThrow(InvalidArgumentException::class);
});

it('ensures payment intent is isolated by merchant', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['merchant_id' => 1, 'amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $intent = DB::table('payment_intents')->where('id', $intentId)->first();
    expect($intent->merchant_id)->toBe(1);
});

it('requires a corresponding ledger posting for successful payments', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['merchant_id' => 1, 'amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);

    $manager->updatePaymentIntentStatus($intentId, 'processing');
    $manager->updatePaymentIntentStatus($intentId, 'succeeded');

    expect($manager->verifyReconciliation($intentId))->toBeTrue();
});

it('ensures failed and cancelled payments do not create capture ledger entries', function () {
    $manager = new PaymentRecoveryManager();
    $failedIntent = $manager->createPaymentIntent(['merchant_id' => 1, 'amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);
    $manager->updatePaymentIntentStatus($failedIntent, 'processing');
    $manager->updatePaymentIntentStatus($failedIntent, 'failed');

    $cancelledIntent = $manager->createPaymentIntent(['merchant_id' => 1, 'amount' => 5000, 'currency' => 'USD', 'status' => 'pending']);
    $manager->updatePaymentIntentStatus($cancelledIntent, 'cancelled');

    expect($manager->verifyReconciliation($failedIntent))->toBeTrue();
    expect($manager->verifyReconciliation($cancelledIntent))->toBeTrue();
});

it('satisfies the full integration invariant from intent to ledger reconciliation', function () {
    $manager = new PaymentRecoveryManager();
    $intentId = $manager->createPaymentIntent(['merchant_id' => 1, 'amount' => 12000, 'currency' => 'USD', 'status' => 'pending']);

    // Intent -> Attempt / Processing -> Succeeded -> Ledger Posting -> Reconciled
    $manager->updatePaymentIntentStatus($intentId, 'processing');
    $manager->updatePaymentIntentStatus($intentId, 'succeeded');

    $isReconciled = $manager->verifyReconciliation($intentId);
    expect($isReconciled)->toBeTrue();
    expect($manager->calculateBalance(1, 'USD'))->toBe(12000);
});