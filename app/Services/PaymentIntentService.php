<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Contracts\PaymentProcessorInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentIntentService
{
    public function __construct(
        protected PaymentOrchestrationService $orchestrationService,
        protected PaymentProcessorInterface $defaultProcessor,
        protected ?PaymentProcessorInterface $fallbackProcessor = null
    ) {}

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

        $result = DB::transaction(function () use ($merchant, $idempotencyKey, $requestHash, $normalizedPayload) {
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
                'intent_model' => $paymentIntent,
            ];
        });

        // If it's a conflict error (409) or an existing cached intent, return immediately
        if (isset($result['status_code']) && $result['status_code'] === 409) {
            return $result;
        }

        // If a new intent was created, run it through the orchestration pipeline outside the main DB lock transaction
        if (isset($result['intent_model'])) {
            $intent = $result['intent_model'];
            unset($result['intent_model']);

            try {
                $this->orchestrationService->process(
                    $intent,
                    $this->defaultProcessor,
                    $this->fallbackProcessor
                );

                // Update final status in response payload
                $result['data']['status'] = $intent->fresh()->status;
            } catch (Throwable $e) {
                $result['status_code'] = 400;
                $result['data']['status'] = $intent->fresh()->status;
                $result['data']['error'] = $e->getMessage();
            }
        }

        return $result;
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