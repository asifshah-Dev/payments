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
        Schema::create('payment_intents', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->foreignUuid('merchant_id')
        ->constrained('merchants')
        ->cascadeOnDelete();

    $table->unsignedBigInteger('amount');

    $table->char('currency', 3);

    $table->text('description')->nullable();

    $table->string('status')->default('pending');

    $table->string('idempotency_key');

    $table->char('request_hash', 64);

    $table->timestamps();

    $table->unique(['merchant_id', 'idempotency_key']);
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_intent');
    }
};