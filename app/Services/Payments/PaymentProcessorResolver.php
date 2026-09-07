<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProcessor;
use App\Services\Payments\Processors\MockProcessor;
use InvalidArgumentException;

class PaymentProcessorResolver
{
    public function resolve(string $gateway): PaymentProcessor
    {
        return match ($gateway) {
            'mock', 'stripe' => new MockProcessor(true),
            'mock_fail', 'stripe_fail' => new MockProcessor(false),
            default => throw new InvalidArgumentException("Unsupported payment gateway [{$gateway}]."),
        };
    }
}