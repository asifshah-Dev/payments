<?php

namespace App\Services\Payments\Processors;

use App\Models\PaymentAttempt;

interface PaymentProcessorInterface
{
    public function charge(PaymentAttempt $attempt): ProcessorResult;
}