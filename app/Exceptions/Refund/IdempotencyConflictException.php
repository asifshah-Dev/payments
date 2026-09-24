<?php

namespace App\Exceptions\Refund;

use RuntimeException;

class IdempotencyConflictException extends RuntimeException
{
    public function __construct(string $message = 'Idempotency-Key was reused with different parameters.')
    {
        parent::__construct($message);
    }
}