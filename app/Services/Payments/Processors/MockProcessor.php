<?php

namespace App\Services\Payments\Processors;

use App\Contracts\PaymentProcessor;
use App\Models\PaymentAttempt;
use App\Services\ProcessorResult;
use Illuminate\Support\Str;

class MockProcessor implements PaymentProcessor
{
    public function __construct(protected bool $shouldSucceed = true) {}

    public function charge(PaymentAttempt $attempt): ProcessorResult
    {
        if ($this->shouldSucceed) {
            return ProcessorResult::success('mock_ch_' . Str::random(24));
        }

        return ProcessorResult::failure('card_declined', 'The card was declined by the mock processor.');
    }
}