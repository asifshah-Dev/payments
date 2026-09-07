<?php

namespace App\Services;

use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\Payments\PaymentProcessorResolver;
use RuntimeException;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

class PaymentAttemptService
{
    public function __construct(
        protected PaymentProcessorResolver $resolver
    ) {}

    public function create(
        string $paymentIntentId,
        string $processor,
        ?string $processorReferenceId = null
    ): PaymentAttempt {
        $paymentIntent = PaymentIntent::find($paymentIntentId);

        if (!$paymentIntent) {
            throw new RuntimeException('Payment intent not found.');
        }

        $allowedProcessors = [
            'stripe',
            'paypal',
            'mock',
            'mock_fail',
        ];

        if (!in_array($processor, $allowedProcessors, true)) {
            throw new InvalidArgumentException("Unsupported processor [{$processor}].");
        }

        return PaymentAttempt::create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => $processor,
            'status' => 'pending',
            'amount' => $paymentIntent->amount,
            'currency' => $paymentIntent->currency,
            'processor_reference_id' => $processorReferenceId,
        ]);
    }

    public function process(PaymentAttempt $attempt): PaymentAttempt
    {
        // 1. Transition to processing
        $attempt = $this->transition($attempt, 'processing');

        // 2. Resolve the processor and execute the charge
        $processorInstance = $this->resolver->resolve($attempt->processor);
        $result = $processorInstance->charge($attempt);

        return DB::transaction(function () use ($attempt, $result) {
            if ($result->successful) {
                $attempt->update([
                    'processor_reference_id' => $result->processorReference,
                ]);

                // Update attempt to succeeded and sync intent
                $attempt = $this->transition($attempt, 'succeeded');
                
                $intent = $attempt->paymentIntent()->lockForUpdate()->first();
                if ($intent->status !== 'succeeded') {
                    $intent->transitionTo('succeeded');
                }

                return $attempt->fresh();
            } else {
                $attempt->update([
                    'failure_code' => $result->errorCode,
                    'failure_message' => $result->errorMessage,
                ]);

                // Update attempt to failed and sync intent
                $attempt = $this->transition($attempt, 'failed');

                $intent = $attempt->paymentIntent()->lockForUpdate()->first();
                if ($intent->status !== 'failed') {
                    $intent->transitionTo('failed');
                }

                return $attempt->fresh();
            }
        });
    }

    public function transition(
        PaymentAttempt $attempt,
        string $toStatus
    ): PaymentAttempt {
        $allowedTransitions = [
            'pending' => [
                'processing',
                'failed',
            ],
            'processing' => [
                'succeeded',
                'failed',
            ],
            'succeeded' => [],
            'failed' => [],
        ];

        $fromStatus = $attempt->status;

        if ($fromStatus === $toStatus) {
            return $attempt;
        }

        if (!array_key_exists($fromStatus, $allowedTransitions)) {
            throw new InvalidArgumentException("Invalid current payment attempt status [{$fromStatus}].");
        }

        if (!in_array($toStatus, $allowedTransitions[$fromStatus], true)) {
            throw new InvalidArgumentException("Cannot transition payment attempt from [{$fromStatus}] to [{$toStatus}].");
        }

        return DB::transaction(function () use ($attempt, $toStatus) {
            $attempt->refresh();
            $attempt->status = $toStatus;
            $attempt->save();

            $paymentIntent = $attempt->paymentIntent()->lockForUpdate()->first();

            if (!$paymentIntent) {
                throw new RuntimeException('Payment intent associated with the payment attempt was not found.');
            }

            if (in_array($toStatus, ['processing', 'succeeded', 'failed'], true)) {
                if ($paymentIntent->status !== $toStatus) {
                    $paymentIntent->transitionTo($toStatus);
                }
            }

            return $attempt->fresh();
        });
    }
}