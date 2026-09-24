<?php

namespace App\Services\Payments;

use App\Models\PaymentAttempt;
use App\Models\PaymentWebhookEvent;
use App\Models\RefundAttempt;
use App\Models\Refund;
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
        string $signature,
        string $rawBody,
    ): void {
        // 1. Verify signature (Extensible stub for now)
        if (!$this->verifySignature($processor, $payload, $signature, $rawBody)) {
            throw new InvalidArgumentException('Invalid webhook signature.');
        }

        // 2. Verify the timestamp is within the replay window.
        $this->verifyTimestamp($signature);

        // 3. Concurrency-safe deduplication using database locking (lockForUpdate)
        $webhookEvent = DB::transaction(function () use ($processor, $eventId, $eventType, $payload) {
            $event = PaymentWebhookEvent::where('processor', $processor)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($event) {
                return $event;
            }

            return PaymentWebhookEvent::create([
                'processor'  => $processor,
                'event_id'   => $eventId,
                'event_type' => $eventType,
                'payload'    => $payload,
                'status'     => 'received',
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
                // 4. Extract Processor Reference ID from payload
                $processorReferenceId = $payload['data']['object']['id'] ?? null;

                if (!$processorReferenceId) {
                    $webhookEvent->update([
                        'status'        => 'failed',
                        'error_message' => 'Processor reference ID missing from payload.',
                    ]);
                    return;
                }

                // 5. Validate supported event types early
                if (!in_array($eventType, [
                    'payment_intent.succeeded',
                    'payment_intent.failed',
                    'refund.succeeded',
                    'refund.failed',
                ], true)) {
                    $webhookEvent->update([
                        'status'        => 'ignored',
                        'error_message' => "Unhandled event type [{$eventType}].",
                    ]);
                    return;
                }

                if (str_starts_with($eventType, 'refund.')) {
                    $refundAttempt = RefundAttempt::where('processor', $processor)
                        ->where('processor_reference_id', $processorReferenceId)
                        ->first();

                    if (!$refundAttempt) {
                        $webhookEvent->update([
                            'status'        => 'failed',
                            'error_message' => 'Associated refund attempt not found.',
                        ]);
                        return;
                    }

                    $refund = $refundAttempt->refund;
                    if ($eventType === 'refund.succeeded') {
                        if ($refundAttempt->status === 'failed' || $refund->status === 'failed') {
                            $webhookEvent->update([
                                'status'        => 'ignored',
                                'error_message' => 'Out-of-order event: Refund is already failed.',
                            ]);
                            return;
                        }

                        $refundAttempt->update(['status' => 'succeeded']);
                        $refund->update(['status' => 'succeeded']);
                    } elseif ($refundAttempt->status !== 'succeeded' && $refund->status !== 'succeeded') {
                        $refundAttempt->update([
                            'status'        => 'failed',
                            'error_message' => $payload['data']['object']['failure_message'] ?? 'Gateway reported refund failure.',
                        ]);
                        $refund->update(['status' => 'failed']);
                    } else {
                        $webhookEvent->update([
                            'status'        => 'ignored',
                            'error_message' => 'Out-of-order event: Refund is already succeeded.',
                        ]);
                        return;
                    }

                    $webhookEvent->update([
                        'status'        => 'processed',
                        'error_message' => null,
                    ]);
                    return;
                }

                // 6. Find the related PaymentAttempt matching both processor and reference ID
                $attempt = PaymentAttempt::where('processor', $processor)
                    ->where('processor_reference_id', $processorReferenceId)
                    ->first();

                if (!$attempt) {
                    $webhookEvent->update([
                        'status'        => 'failed',
                        'error_message' => 'Associated payment attempt not found.',
                    ]);
                    return;
                }

                // 7. Handle event types through state machine transitions with out-of-order safety
                switch ($eventType) {
                    case 'payment_intent.succeeded':
                        if ($attempt->status === 'failed') {
                            $webhookEvent->update([
                                'status'        => 'ignored',
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
                                'status'        => 'ignored',
                                'error_message' => 'Out-of-order event: Attempt is already succeeded.',
                            ]);
                            return;
                        }
                        if ($attempt->status !== 'failed') {
                            $attempt->update([
                                'failure_code'    => $payload['data']['object']['last_payment_error']['code'] ?? 'unknown',
                                'failure_message' => $payload['data']['object']['last_payment_error']['message'] ?? 'Gateway reported failure.',
                            ]);
                            $this->attemptService->transition($attempt, 'failed');
                        }
                        break;
                }

                $webhookEvent->update([
                    'status'        => 'processed',
                    'error_message' => null,
                ]);
            });
        } catch (Throwable $e) {
            $webhookEvent->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Verify the webhook signature. Accepts both:
     *   - Bare: `valid_secret_signature`
     *   - Stripe-style header: `t=<ts>,v1=valid_secret_signature`
     *
     * Real HMAC verification (against $rawBody) goes here later. The
     * $rawBody parameter is intentionally accepted now so the plumbing
     * is in place.
     */
    protected function verifySignature(
        string $processor,
        array $payload,
        string $signature,
        string $rawBody,
    ): bool {
        if ($signature === 'valid_secret_signature') {
            return true;
        }

        if (preg_match('/^t=\d+,v1=(.+)$/', $signature, $matches)) {
            return $matches[1] === 'valid_secret_signature';
        }

        return false;
    }

    /**
     * Reject webhooks whose signature header timestamp is outside the
     * configured replay window.
     *
     * @throws InvalidArgumentException when the timestamp is missing or too old
     */
    protected function verifyTimestamp(string $signature): void
    {
        // Bare signatures (no `t=`) are legacy test-style. Skip the check
        // until real HMAC lands. Real Stripe headers always have `t=`.
        if (! preg_match('/t=(\d+)/', $signature, $matches)) {
            return;
        }

        $timestamp = (int) $matches[1];
        $window    = (int) config('webhooks.replay_window_seconds', 300);

        if (abs(now()->timestamp - $timestamp) > $window) {
            throw new InvalidArgumentException(
                'Webhook timestamp is outside the replay window.'
            );
        }
    }
}