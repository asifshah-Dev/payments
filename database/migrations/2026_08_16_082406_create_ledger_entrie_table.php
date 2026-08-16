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
        Schema::create('ledger_entries', function (Blueprint $table) {
    $table->uuid('id')->primary();

    $table->foreignUuid('ledger_transaction_id')
          ->constrained('ledger_transactions')
          ->restrictOnDelete();

    $table->foreignUuid('ledger_account_id')
          ->constrained('ledger_accounts')
          ->restrictOnDelete();

    $table->string('type', 6);
    // debit / credit

    $table->unsignedBigInteger('amount');

    $table->char('currency', 3);

    $table->timestamps();

    $table->index(['ledger_account_id', 'created_at']);
    $table->index(['ledger_transaction_id', 'type']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entrie');
    }
};
