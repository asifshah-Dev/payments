<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_account_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('context', 50);

            $table->char('currency', 3);

            $table->string('debit_account_role', 50);

            $table->string('credit_account_role', 50);

            $table->timestamps();

            $table->unique(
                ['context', 'currency'],
                'ledger_account_mapping_context_currency_unique'
            );

            // Shortened custom index name to avoid MySQL 64-character limit
            $table->index(['debit_account_role', 'credit_account_role'], 'lam_debit_credit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_account_mappings');
    }
};