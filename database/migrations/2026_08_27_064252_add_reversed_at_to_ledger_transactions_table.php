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
    Schema::table('ledger_transactions', function (Blueprint $table) {
        $table->timestamp('reversed_at')->nullable()->after('posted_at');
    });
}

public function down(): void
{
    Schema::table('ledger_transactions', function (Blueprint $table) {
        $table->dropColumn('reversed_at');
    });
}
};
