<?php

namespace App\Services;

use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use Throwable;
use Exception;

class PaymentOrchestrationService
{
    protected const MAX_ATTEMPTS = 3;

    public function process(PaymentIntent $intent, object $primaryProcessor, ?object $fallbackProcessor = null): array
    {
        // 1. Terminal-state protection
        if (in_array($intent->status, ['succeeded', 'canceled'], true)) {
            throw new Exception("Cannot process intent in terminal state: {$intent->status}");
        }

        $attemptNumber = $intent->attempts()->count() + 1;

        // 2. Record the attempt before hitting the gateway
        $attempt = PaymentAttempt::create([
            'payment_intent_id' => $intent->id,
            'processor' => get_class($primaryProcessor),
            'status' => 'processing',
            'amount' => $intent->amount,
            'currency' => $intent->currency,
            'attempt_number' => $attemptNumber,
        ]);

        try {
            $result = $primaryProcessor->charge([
                'amount' => $intent->amount,
                'currency' => $intent->currency,
                'idempotency_key' => $intent->idempotency_key,
            ]);

            // Mark attempt and intent as successful
            DB::transaction(function () use ($intent, $attempt, $result) {
                $attempt->update([
                    'status' => 'succeeded',
                    'processor_reference_id' => $result['id'] ?? null,
                    'raw_response' => $result,
                ]);

                $intent->update(['status' => 'succeeded']);
            });

            return $result;

        } catch (Throwable $e) {
            $isRetryable = $this->isRetryable($e);

            $attempt->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // Handle retry on same processor or fallback
            if ($isRetryable && $attemptNumber < self::MAX_ATTEMPTS) {
                return $this->process($intent, $primaryProcessor, $fallbackProcessor);
            }

            if ($fallbackProcessor && $attemptNumber >= self::MAX_ATTEMPTS) {
                return $this->process($intent, $fallbackProcessor, null);
            }

            // Hard failure or non-retryable
            $intent->update(['status' => 'failed']);
            throw $e;
        }
    }

    protected function isRetryable(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'timeout') || 
               str_contains($message, 'rate_limit') || 
               str_contains($message, 'connection');
    }
}