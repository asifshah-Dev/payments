<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFeeAmountToPaymentAttemptsTable extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->unsignedInteger('fee_amount')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn('fee_amount');
        });
    }
}