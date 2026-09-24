<?php
// database/migrations/2026_09_24_000001_add_api_key_hash_to_merchants_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // Nullable so existing rows survive the migration; a merchant
            // without a key simply cannot authenticate.
            $table->char('api_key_hash', 64)
                  ->nullable()
                  ->unique()
                  ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropUnique(['api_key_hash']);
            $table->dropColumn('api_key_hash');
        });
    }
};