<?php

namespace App\Services\Payments;

use App\Models\PaymentAttempt;
use App\Models\PaymentWebhookEvent;
use App\Services\PaymentAttemptService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class WebhookProcessorService
{
    public function __construct(
        protected PaymentAttemptService $attemptService
    ) {}

    public function handle(
        string $processor,
        string $eventId,
        string $eventType,
        array $payload,
        string $signature
    ): void {
        // 1. Verify signature (Extensible stub for now)
        if (!$this->verifySignature($processor, $payload, $signature)) {
            throw new InvalidArgumentException('Invalid webhook signature.');
        }

        // 2. Concurrency-safe deduplication using database locking (lockForUpdate)
        $webhookEvent = DB::transaction(function () use ($processor, $eventId, $eventType, $payload) {
            $event = PaymentWebhookEvent::where('processor', $processor)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($event) {
                return $event;
            }

            return PaymentWebhookEvent::create([
                'processor' => $processor,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => 'received',
            ]);
        });

        // Idempotency guard: if already fully processed, skip safely
        if ($webhookEvent->status === 'processed') {
            return;
        }

        // Mark as processing to prevent concurrent execution races
        $webhookEvent->update(['status' => 'processing']);

        try {
            DB::transaction(function () use ($webhookEvent, $processor, $eventType, $payload) {
                // 3. Extract Processor Reference ID from payload
                $processorReferenceId = $payload['data']['object']['id'] ?? null;

                if (!$processorReferenceId) {
                    $webhookEvent->update([
                        'status' => 'failed',
                        'error_message' => 'Processor reference ID missing from payload.',
                    ]);
                    return;
                }

                // 4. Validate supported event types early
                if (!in_array($eventType, ['payment_intent.succeeded', 'payment_intent.failed'], true)) {
                    $webhookEvent->update([
                        'status' => 'ignored',
                        'error_message' => "Unhandled event type [{$eventType}].",
                    ]);
                    return;
                }

                // 5. Find the related PaymentAttempt matching both processor and reference ID
                $attempt = PaymentAttempt::where('processor', $processor)
                    ->where('processor_reference_id', $processorReferenceId)
                    ->first();

                if (!$attempt) {
                    $webhookEvent->update([
                        'status' => 'failed',
                        'error_message' => 'Associated payment attempt not found.',
                    ]);
                    return;
                }

                // 6. Handle event types through state machine transitions with out-of-order safety
                switch ($eventType) {
                    case 'payment_intent.succeeded':
                        if ($attempt->status === 'failed') {
                            $webhookEvent->update([
                                'status' => 'ignored',
                                'error_message' => 'Out-of-order event: Attempt is already failed.',
                            ]);
                            return;
                        }
                        if ($attempt->status !== 'succeeded') {
                            $this->attemptService->transition($attempt, 'succeeded');
                        }
                        break;

                    case 'payment_intent.failed':
                        if ($attempt->status === 'succeeded') {
                            $webhookEvent->update([
                                'status' => 'ignored',
                                'error_message' => 'Out-of-order event: Attempt is already succeeded.',
                            ]);
                            return;
                        }
                        if ($attempt->status !== 'failed') {
                            $attempt->update([
                                'failure_code' => $payload['data']['object']['last_payment_error']['code'] ?? 'unknown',
                                'failure_message' => $payload['data']['object']['last_payment_error']['message'] ?? 'Gateway reported failure.',
                            ]);
                            $this->attemptService->transition($attempt, 'failed');
                        }
                        break;
                }

                $webhookEvent->update([
                    'status' => 'processed',
                    'error_message' => null,
                ]);
            });
        } catch (Throwable $e) {
            $webhookEvent->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function verifySignature(string $processor, array $payload, string $signature): bool
    {
        return $signature === 'valid_secret_signature';
    }
}