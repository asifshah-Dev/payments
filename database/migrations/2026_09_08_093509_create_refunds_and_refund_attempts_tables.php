<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('payment_intent_id')
                ->constrained('payment_intents')
                ->restrictOnDelete();

            $table->foreignUuid('merchant_id')
                ->constrained('merchants')
                ->restrictOnDelete();

            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);

            $table->string('status')->default('pending');

            $table->string('reason')->nullable();

            $table->string('idempotency_key');

            $table->char('request_hash', 64);

            $table->timestamps();

            $table->unique([
                'merchant_id',
                'idempotency_key',
            ]);
        });

        Schema::create('refund_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('refund_id')
                ->constrained('refunds')
                ->restrictOnDelete();

            $table->string('processor');

            $table->string('status');

            $table->unsignedInteger('attempt_number');

            $table->string('processor_reference_id')->nullable();

            $table->text('error_message')->nullable();

            $table->json('raw_response')->nullable();

            $table->timestamps();

            $table->unique([
                'refund_id',
                'attempt_number',
            ]);

            $table->unique([
                'processor',
                'processor_reference_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_attempts');
        Schema::dropIfExists('refunds');
    }
};
