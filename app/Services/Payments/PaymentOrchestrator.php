<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\PaymentAttempt;
use App\Services\PaymentAttemptService;
use InvalidArgumentException;

class PaymentOrchestrator
{
    public function __construct(
        protected PaymentAttemptService $attemptService
    ) {}

    public function processWithFallback(PaymentIntent $intent, array $processors): PaymentAttempt
    {
        if (empty($processors)) {
            throw new InvalidArgumentException('At least one processor must be provided for fallback processing.');
        }

        $lastAttempt = null;

        foreach ($processors as $processorName) {
            $attempt = $this->attemptService->create(
                paymentIntentId: $intent->id,
                processor: $processorName
            );

            try {
                $processedAttempt = $this->attemptService->process($attempt);

                if ($processedAttempt->status === 'succeeded') {
                    return $processedAttempt;
                }

                $lastAttempt = $processedAttempt;
            } catch (\Throwable $e) {
                if ($attempt->status === 'processing') {
                    $attempt->update([
                        'failure_code' => 'exception',
                        'failure_message' => $e->getMessage(),
                    ]);
                    $lastAttempt = $this->attemptService->transition($attempt, 'failed');
                } else {
                    $lastAttempt = $attempt;
                }
            }

            $intent->refresh();
            if ($intent->status === 'failed' && $processorName !== end($processors)) {
                $intent->transitionTo('processing');
            }
        }

        return $lastAttempt;
    }
}