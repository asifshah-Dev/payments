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
       Schema::create('ledger_accounts', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->uuid('merchant_id')->nullable();



    $table->string('name', 150);

    $table->string('type', 30);
    // asset, liability, revenue, expense

    $table->char('currency', 3);

    $table->string('status', 20)->default('active');
    // active, frozen, closed

    $table->timestamps();

    $table->index(['type', 'status']);
    $table->unique(['merchant_id',  'type', 'currency'], 'merchant_account_unique');
    $table->foreign('merchant_id')
                ->references('id')
                ->on('merchants')
                ->cascadeOnRestrict();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
