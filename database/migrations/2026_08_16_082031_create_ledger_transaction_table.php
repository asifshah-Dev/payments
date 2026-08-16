<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_transactions', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->string('type', 50);
    
    $table->uuid('payment_attempt_id')->nullable();

    $table->string('reference_type', 100)->nullable();
    $table->uuid('reference_id')->nullable();

    $table->text('description')->nullable();

    $table->timestamp('posted_at');

    $table->timestamps();

    $table->foreign('payment_attempt_id')
          ->references('id')
          ->on('payment_attempts')
          ->restrictOnDelete();

    $table->index(['reference_type', 'reference_id']);
    $table->index('payment_attempt_id');
    $table->index('type');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_transaction');
    }
};
