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

    $table->string('name', 150);

    $table->string('type', 30);
    // asset, liability, revenue, expense

    $table->char('currency', 3);

    $table->string('status', 20)->default('active');
    // active, frozen, closed

    $table->timestamps();

    $table->index(['type', 'status']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_account');
    }
};
