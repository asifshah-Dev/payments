<?php

namespace App\Services;

use App\Models\IncomingWebhookLog;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class IncomingWebhookProcessingService
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_TIMEOUT_SECONDS = 300;

    public function process(IncomingWebhookLog $webhook): IncomingWebhookLog
{
    /*
     * Phase 1:
     * Acquire the webhook lock and commit it.
     *
     * This must be committed separately so that a later
     * processing failure does not roll back the lock/attempt count.
     */
    $webhook = DB::transaction(function () use ($webhook) {

        $webhook = IncomingWebhookLog::whereKey($webhook->id)
            ->lockForUpdate()
            ->first();

        if (!$webhook) {
            throw new RuntimeException(
                'Incoming webhook not found.'
            );
        }

        if ($webhook->status === 'processed') {
            return $webhook;
        }

        if ($webhook->attempt_count >= self::MAX_ATTEMPTS) {
            throw new RuntimeException(
                'Webhook has exceeded the maximum number of processing attempts.'
            );
        }

        if (
            $webhook->locked_at !== null &&
            $webhook->locked_at->gt(
                now()->subSeconds(self::LOCK_TIMEOUT_SECONDS)
            )
        ) {
            throw new RuntimeException(
                'Webhook is currently being processed.'
            );
        }

        $workerId = gethostname() . ':' . uniqid('', true);

        $webhook->update([
            'locked_at' => now(),
            'locked_by' => $workerId,
            'attempt_count' => $webhook->attempt_count + 1,
        ]);

        return $webhook->fresh();
    });

    /*
     * Already processed.
     */
    if ($webhook->status === 'processed') {
        return $webhook;
    }

    /*
     * Phase 2:
     * Process the webhook OUTSIDE the locking transaction.
     *
     * This allows us to persist "failed" even when processing
     * throws an exception.
     */
    try {
        $payload = $webhook->payload;

        $paymentAttemptId = $payload['payment_attempt_id'] ?? null;

        if (!$paymentAttemptId) {
            throw new RuntimeException(
                'Webhook does not contain a payment attempt id.'
            );
        }

        $attempt = PaymentAttempt::find($paymentAttemptId);

        if (!$attempt) {
            throw new RuntimeException(
                'Payment attempt not found.'
            );
        }

        $status = match ($webhook->event_type) {
            'payment.processing' => 'processing',

            'payment.succeeded' => 'succeeded',

            'payment.failed' => 'failed',

            default => throw new InvalidArgumentException(
                "Unsupported webhook event type [{$webhook->event_type}]."
            ),
        };

        app(PaymentAttemptService::class)->transition(
            attempt: $attempt,
            toStatus: $status,
        );

        $webhook->update([
            'status' => 'processed',
            'processed_at' => now(),
            'error_message' => null,
            'locked_at' => null,
            'locked_by' => null,
        ]);

        return $webhook->fresh();

    } catch (\Throwable $e) {

        /*
         * This update is deliberately OUTSIDE the transaction
         * used to acquire the lock.
         */
        $webhook->update([
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'locked_at' => null,
            'locked_by' => null,
        ]);

        throw $e;
    }
}
}