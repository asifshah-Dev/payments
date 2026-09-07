<?php

namespace App\Services;

class ProcessorResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $processorReference = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $rawResponse = []
    ) {}

    public static function success(string $reference, array $raw = []): self
    {
        return new self(successful: true, processorReference: $reference, rawResponse: $raw);
    }

    public static function failure(string $code, string $message, array $raw = []): self
    {
        return new self(successful: false, errorCode: $code, errorMessage: $message, rawResponse: $raw);
    }
}