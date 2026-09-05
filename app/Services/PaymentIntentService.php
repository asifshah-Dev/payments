<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;

class PaymentIntentService
{
    /**
     * Create or retrieve an idempotent payment intent.
     */
    public function createOrGet(Merchant $merchant, array $data, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);

        $normalizedPayload = [
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'description' => $data['description'] ?? null,
        ];

        $requestHash = hash('sha256', json_encode($normalizedPayload));

        return DB::transaction(function () use ($merchant, $idempotencyKey, $requestHash, $normalizedPayload) {
            $existing = PaymentIntent::where('merchant_id', $merchant->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->request_hash !== $requestHash) {
                    return [
                        'status_code' => 409,
                        'data' => [
                            'message' => 'Idempotency key was already used with a different request.',
                        ],
                    ];
                }

                return [
                    'status_code' => 201,
                    'data' => [
                        'id' => $existing->id,
                        'amount' => $existing->amount,
                        'currency' => $existing->currency,
                        'status' => $existing->status,
                        'description' => $existing->description,
                    ],
                ];
            }

            $paymentIntent = PaymentIntent::create([
                'merchant_id' => $merchant->id,
                'amount' => $normalizedPayload['amount'],
                'currency' => $normalizedPayload['currency'],
                'description' => $normalizedPayload['description'],
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);

            return [
                'status_code' => 201,
                'data' => [
                    'id' => $paymentIntent->id,
                    'amount' => $paymentIntent->amount,
                    'currency' => $paymentIntent->currency,
                    'status' => $paymentIntent->status,
                    'description' => $paymentIntent->description,
                ],
            ];
        });
    }

    /**
     * Find a payment intent for a merchant.
     */
    public function findForMerchant(Merchant $merchant, string $id): ?PaymentIntent
    {
        return PaymentIntent::where('id', $id)
            ->where('merchant_id', $merchant->id)
            ->first();
    }
}