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
       Schema::table('merchants', function (Blueprint $table) {
        $table->string('api_key_hash', 64)
    ->nullable()
    ->unique()
    ->after('email');
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::table('merchants', function (Blueprint $table) {
        $table->dropUnique(['api_key_hash']);
        $table->dropColumn('api_key_hash');
    });
    }
};
