<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefundRequest;
use App\Models\PaymentIntent;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class RefundController extends Controller
{
    public function __construct(
        protected RefundService $refundService
    ) {}

    public function store(StoreRefundRequest $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        // Scoped lookup: this is what makes cross-merchant access a 404,
        // not a 403. We never load another merchant's PI.
        $payment = PaymentIntent::query()
            ->where('id', $request->validated('payment_intent_id'))
            ->where('merchant_id', $merchant->id)
            ->first();

        if (! $payment) {
            throw new NotFoundHttpException('Payment intent not found.');
        }

        if ($payment->status !== 'succeeded') {
            throw new UnprocessableEntityHttpException(
                'Only succeeded payments can be refunded.'
            );
        }

        $result = $this->refundService->refund(
            merchant:       $merchant,
            payment:        $payment,
            amount:         (int) $request->validated('amount'),
            reason:         $request->validated('reason'),
            idempotencyKey: $request->validated('idempotency_key'),
        );

        return response()->json($result['data'], $result['status_code']);
    }
}