<?php

namespace App\Services\Payments\Processors;

class ProcessorResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $processorReferenceId = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly array $rawResponse = []
    ) {}

    public static function success(string $processorReferenceId, array $rawResponse = []): self
    {
        return new self(
            successful: true,
            processorReferenceId: $processorReferenceId,
            rawResponse: $rawResponse
        );
    }

    public static function failure(string $failureCode, string $failureMessage, array $rawResponse = []): self
    {
        return new self(
            successful: false,
            failureCode: $failureCode,
            failureMessage: $failureMessage,
            rawResponse: $rawResponse
        );
    }
}