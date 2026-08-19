<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentIntentRequest;
use App\Services\PaymentIntentService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PaymentIntentController extends Controller
{
    public function __construct(
        private PaymentIntentService $paymentIntentService
    ) {
    }

    public function store(StorePaymentIntentRequest $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        try {
            $paymentIntent = $this->paymentIntentService->create(
                merchantId: $merchant->id,
                amount: $request->integer('amount'),
                currency: $request->string('currency')->toString(),
                description: $request->input('description'),
                idempotencyKey: $request->validated()['idempotency_key'],
            );
        }catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'id' => $paymentIntent->id,
            'merchant_id' => $paymentIntent->merchant_id,
            'amount' => $paymentIntent->amount,
            'currency' => $paymentIntent->currency,
            'description' => $paymentIntent->description,
            'status' => $paymentIntent->status,
        ], 201);
    }
}