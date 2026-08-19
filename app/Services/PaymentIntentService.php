<?php

namespace App\Services;

use App\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentIntentService
{
    
    public function create(
        string $merchantId,
        int $amount,
        string $currency,
        ?string $description,
        string $idempotencyKey
    ): PaymentIntent {
        $requestHash = $this->buildRequestHash(
            $amount,
            $currency,
            $description
        );

        return DB::transaction(function () use (
            $merchantId,
            $amount,
            $currency,
            $description,
            $idempotencyKey,
            $requestHash
        ) {
            $existing = PaymentIntent::where('merchant_id', $merchantId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->request_hash !== $requestHash) {
                    throw new RuntimeException(
                        'Idempotency key was already used with a different request.'
                    );
                }

                return $existing;
            }
    if ($amount <= 0) {
    throw new \InvalidArgumentException('Payment amount must be greater than zero.');
}
$currency = strtoupper($currency);

$allowedCurrencies = [
    'USD',
    'EUR',
    'GBP',
    'PKR',
];


if (!in_array($currency, $allowedCurrencies, true)) {
    throw new \InvalidArgumentException(
        "Unsupported currency [{$currency}]."
    );
}
if (trim($idempotencyKey) === '') {
    throw new \InvalidArgumentException(
        'Idempotency key must not be empty.'
    );
}
if (strlen($idempotencyKey) > 255) {
    throw new \InvalidArgumentException(
        'Idempotency key must not exceed 255 characters.'
    );
}
            return PaymentIntent::create([
                'merchant_id' => $merchantId,
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'description' => $description,
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);
        });
    }

    private function buildRequestHash(
        int $amount,
        string $currency,
        ?string $description
    ): string {
        return hash(
            'sha256',
            json_encode([
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'description' => $description,
            ], JSON_THROW_ON_ERROR)
        );
    }
}