<?php

namespace App\Services;

use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use RuntimeException;
use InvalidArgumentException;

use Illuminate\Support\Facades\DB;

class PaymentAttemptService
{
    public function create(
        string $paymentIntentId,
        string $processor,
        ?string $processorReferenceId = null
    ): PaymentAttempt {
        $paymentIntent = PaymentIntent::find($paymentIntentId);

        if (!$paymentIntent) {
            throw new RuntimeException(
                'Payment intent not found.'
            );
        }

        $allowedProcessors = [
            'stripe',
            'paypal',
        ];

        if (!in_array($processor, $allowedProcessors, true)) {
            throw new InvalidArgumentException(
                "Unsupported processor [{$processor}]."
            );
        }

        return PaymentAttempt::create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => $processor,
            'status' => 'pending',
            'amount' => $paymentIntent->amount,
            'currency' => $paymentIntent->currency,
            'processor_reference_id' => $processorReferenceId,
        ]);
    }
    public function transition(
    PaymentAttempt $attempt,
    string $toStatus
): PaymentAttempt {
    $allowedTransitions = [
        'pending' => [
            'processing',
        ],
        'processing' => [
            'succeeded',
            'failed',
        ],
        'succeeded' => [],
        'failed' => [],
    ];

    $fromStatus = $attempt->status;

    if (!array_key_exists($fromStatus, $allowedTransitions)) {
        throw new InvalidArgumentException(
            "Invalid current payment attempt status [{$fromStatus}]."
        );
    }

    if (!array_key_exists($toStatus, [
        'pending' => true,
        'processing' => true,
        'succeeded' => true,
        'failed' => true,
    ])) {
        throw new InvalidArgumentException(
            "Invalid payment attempt status [{$toStatus}]."
        );
    }

    if (!in_array($toStatus, $allowedTransitions[$fromStatus], true)) {
        throw new InvalidArgumentException(
            "Cannot transition payment attempt from [{$fromStatus}] to [{$toStatus}]."
        );
    }

    return DB::transaction(function () use ($attempt, $toStatus) {
        $attempt->refresh();

        $attempt->status = $toStatus;
        $attempt->save();

        $paymentIntent = $attempt->paymentIntent()->lockForUpdate()->first();

        if (!$paymentIntent) {
            throw new \RuntimeException(
                'Payment intent associated with the payment attempt was not found.'
            );
        }

        $paymentIntent->status = $toStatus;
        $paymentIntent->save();

        return $attempt->fresh();
    });
}
}