<?php

namespace App\Services\Payments\Processors;

use App\Models\PaymentAttempt;

class PayPalProcessorAdapter implements PaymentProcessorInterface
{
    public function charge(PaymentAttempt $attempt): ProcessorResult
    {
        // Simulated PayPal interaction
        $referenceId = 'PAYID-' . strtoupper(fake()->bothify('??##########'));

        return ProcessorResult::success(
            processorReferenceId: $referenceId,
            rawResponse: ['id' => $referenceId, 'state' => 'approved']
        );
    }
}