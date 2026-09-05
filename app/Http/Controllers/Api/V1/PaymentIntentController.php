<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentIntentRequest;
use App\Services\PaymentIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentIntentController extends Controller
{
    public function __construct(
        protected PaymentIntentService $paymentIntentService
    ) {}

    public function store(StorePaymentIntentRequest $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        $result = $this->paymentIntentService->createOrGet(
            $merchant,
            $request->validated(),
            $request->header('Idempotency-Key')
        );

        return response()->json($result['data'], $result['status_code']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        $paymentIntent = $this->paymentIntentService->findForMerchant($merchant, $id);

        if (!$paymentIntent) {
            return response()->json([
                'message' => 'Payment intent not found.',
            ], 404);
        }

        return response()->json([
            'id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
            'currency' => $paymentIntent->currency,
            'status' => $paymentIntent->status,
            'description' => $paymentIntent->description,
        ]);
    }
}