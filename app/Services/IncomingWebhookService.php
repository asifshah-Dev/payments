<?php

namespace App\Services;

use App\Models\IncomingWebhookLog;
use Illuminate\Support\Facades\DB;

class IncomingWebhookService
{
    public function receive(
        string $processor,
        string $processorEventId,
        string $eventType,
        array $payload
    ): IncomingWebhookLog {
        return DB::transaction(function () use (
            $processor,
            $processorEventId,
            $eventType,
            $payload
        ) {
            $existing = IncomingWebhookLog::where('processor', $processor)
                ->where('processor_event_id', $processorEventId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return IncomingWebhookLog::create([
                'processor' => $processor,
                'processor_event_id' => $processorEventId,
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => 'received',
                'attempt_count' => 0,
                'received_at' => now(),
            ]);
        });
    }
}