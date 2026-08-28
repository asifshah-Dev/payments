<?php

use App\Services\PaymentConsistencyChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::disableForeignKeyConstraints();
    Schema::dropIfExists('consistency_ledger_transactions');
    Schema::dropIfExists('consistency_payments');
    Schema::enableForeignKeyConstraints();

    Schema::create('consistency_payments', function (Blueprint $table) {
        $table->id();
        $table->string('status');
        $table->unsignedBigInteger('amount');
        $table->string('currency');
        $table->timestamps();
    });

    Schema::create('consistency_ledger_transactions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('reference_id')->nullable();
        $table->unsignedBigInteger('amount');
        $table->string('currency');
        $table->string('direction')->default('debit');
        $table->timestamp('posted_at')->nullable();
        $table->timestamps();
    });
});

it('detects when a succeeded payment has no corresponding ledger transaction', function () {
    $orphanedPaymentId = DB::table('consistency_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 5000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $balancedPaymentId = DB::table('consistency_payments')->insertGetId([
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('consistency_ledger_transactions')->insert([
        'reference_id' => $balancedPaymentId,
        'amount' => 10000,
        'currency' => 'USD',
        'direction' => 'debit',
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $checker = new PaymentConsistencyChecker('consistency_payments', 'consistency_ledger_transactions');
    $inconsistencies = $checker->findInconsistentPayments();

    expect($inconsistencies)->toHaveCount(1)
        ->and($inconsistencies->first()->id)->toBe($orphanedPaymentId);
});