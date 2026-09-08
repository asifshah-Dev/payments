<?php

namespace App\Services\Payments;

use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\PaymentAttemptService;
use App\Services\Payments\Processors\PaymentProcessorInterface;
use App\Services\Payments\Processors\StripeProcessorAdapter;
use App\Services\Payments\Processors\PayPalProcessorAdapter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class PaymentProcessingService
{
    public function __construct(
        protected PaymentAttemptService $attemptService
    ) {}

    public function process(PaymentIntent $intent, string $processor): PaymentAttempt
    {
        // Resolve adapter first so invalid processors fail fast before transaction creation
        $adapter = $this->resolveAdapter($processor);

        return DB::transaction(function () use ($intent, $processor, $adapter) {
            $attempt = PaymentAttempt::create([
                'payment_intent_id' => $intent->id,
                'processor' => $processor,
                'status' => 'processing',
                'amount' => $intent->amount,
                'currency' => $intent->currency,
            ]);

            $intent->update(['status' => 'processing']);

            try {
                $result = $adapter->charge($attempt);

                if ($result->successful) {
                    $attempt->update([
                        'processor_reference_id' => $result->processorReferenceId,
                    ]);
                    $this->attemptService->transition($attempt, 'succeeded');
                } else {
                    $attempt->update([
                        'processor_reference_id' => $result->processorReferenceId,
                        'failure_code' => $result->failureCode,
                        'failure_message' => $result->failureMessage,
                    ]);
                    $this->attemptService->transition($attempt, 'failed');
                }
            } catch (Throwable $e) {
                $attempt->update([
                    'failure_code' => 'exception',
                    'failure_message' => $e->getMessage(),
                ]);
                $this->attemptService->transition($attempt, 'failed');
            }

            return $attempt->fresh();
        });
    }

    protected function resolveAdapter(string $processor): PaymentProcessorInterface
    {
        return match (strtolower($processor)) {
            'stripe' => app(StripeProcessorAdapter::class),
            'paypal' => app(PayPalProcessorAdapter::class),
            default => throw new InvalidArgumentException("Unsupported processor: [{$processor}]"),
        };
    }
}