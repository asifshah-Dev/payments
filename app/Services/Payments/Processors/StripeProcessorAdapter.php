<?php

namespace App\Services\Payments\Processors;

use App\Models\PaymentAttempt;

class StripeProcessorAdapter implements PaymentProcessorInterface
{
    public function charge(PaymentAttempt $attempt): ProcessorResult
    {
        // Simulated Stripe interaction
        $referenceId = 'pi_' . fake()->uuid();

        if ($attempt->amount === 99999) {
            return ProcessorResult::failure(
                failureCode: 'card_declined',
                failureMessage: 'Your card was declined.',
                rawResponse: ['error' => ['code' => 'card_declined']]
            );
        }

        return ProcessorResult::success(
            processorReferenceId: $referenceId,
            rawResponse: ['id' => $referenceId, 'status' => 'succeeded']
        );
    }
}