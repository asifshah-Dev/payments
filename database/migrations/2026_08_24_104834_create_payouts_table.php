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
        Schema::create('payouts', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->foreignUuid('merchant_id')
        ->constrained('merchants')
        ->restrictOnDelete();

    $table->unsignedBigInteger('amount');

    $table->char('currency', 3);

    $table->string('status', 20);

    $table->timestamps();

    $table->index(['merchant_id', 'status']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
