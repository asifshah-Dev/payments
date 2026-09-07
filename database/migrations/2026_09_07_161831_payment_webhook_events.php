<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('processor');

            $table->string('event_id');

            $table->string('event_type');

            $table->json('payload');

            $table->string('status')
                ->default('received')
                ->index();

            $table->text('error_message')->nullable();

            $table->timestamps();

            // Webhook idempotency
            $table->unique([
                'processor',
                'event_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};