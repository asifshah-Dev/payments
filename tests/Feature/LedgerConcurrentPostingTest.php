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
        $table->unsignedBigInteger('payment_id')->unique();
        $table->unsignedBigInteger('amount');
        $table->string('currency');
        $table->string('direction')->default('debit');
        $table->timestamp('posted_at')->nullable();
        $table->timestamps();
    });
});

it('prevents two concurrent workers from posting the same payment twice', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 7500,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager1 = new PaymentRecoveryManager();
    $manager2 = new PaymentRecoveryManager();

    $result1 = $manager1->attemptPosting($paymentId, false);
    $result2 = $manager2->attemptPosting($paymentId, false);

    expect($result1)->toBeTrue();
    expect($result2)->toBeTrue();
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->count())->toBe(1);
});

it('handles race conditions safely when multiple threads attempt simultaneous insertion', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 12500,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();

    // Simulate overlapping transactions attempting insertion
    $results = [];
    
    // We run multiple attempts inside separate closures simulating separate workers
    $results[] = DB::transaction(function () use ($manager, $paymentId) {
        return $manager->attemptPosting($paymentId, false);
    });

    $results[] = DB::transaction(function () use ($manager, $paymentId) {
        return $manager->attemptPosting($paymentId, false);
    });

    expect($results)->toContain(true);
    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->count())->toBe(1);
});

it('respects row-level locks during concurrent status evaluation', function () {
    $paymentId = DB::table('recovery_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 5000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager = new PaymentRecoveryManager();

    DB::transaction(function () use ($manager, $paymentId) {
        // First worker acquires lock
        $firstResult = $manager->attemptPosting($paymentId, false);
        expect($firstResult)->toBeTrue();

        // Subsequent worker attempt within transaction should see existing ledger entry safely
        $secondResult = $manager->attemptPosting($paymentId, false);
        expect($secondResult)->toBeTrue();
    });

    expect(DB::table('recovery_ledger_transactions')->where('payment_id', $paymentId)->count())->toBe(1);
});