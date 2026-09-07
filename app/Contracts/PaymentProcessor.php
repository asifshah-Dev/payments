<?php

namespace App\Contracts;

use App\Models\PaymentAttempt;
use App\Services\ProcessorResult;

interface PaymentProcessor
{
    public function charge(PaymentAttempt $attempt): ProcessorResult;
}