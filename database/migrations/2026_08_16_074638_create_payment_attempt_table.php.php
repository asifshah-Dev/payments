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
        Schema::create('payment_attempts', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->foreignUuid('payment_intent_id')
          ->constrained('payment_intents')
          ->restrictOnDelete();

    $table->string('processor');

    $table->string('status')->index();

    $table->unsignedBigInteger('amount');

    $table->char('currency', 3);

    $table->string('processor_reference_id')->nullable();

    $table->string('failure_code')->nullable();

    $table->text('failure_message')->nullable();

    $table->timestamps();

    $table->unique([
        'processor',
        'processor_reference_id'
    ]);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
