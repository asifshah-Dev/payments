<?php

namespace App\Services;

use App\Exceptions\Refund\IdempotencyConflictException;
use App\Exceptions\Refund\RefundNotAllowedException;
use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\Refund;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RefundService
{
    /**
     * Create a refund, or return the existing one for a replayed idempotency key.
     *
     * @return array{data: array<string, mixed>, status_code: int}
     */
    public function refund(
        Merchant $merchant,
        PaymentIntent $payment,
        int $amount,
        ?string $reason,
        string $idempotencyKey,
    ): array {
        $requestHash = $this->requestHash($payment->id, $amount, $reason);

        // --- 1. Idempotent replay? (fast path, outside the transaction) ---
        $existing = Refund::query()
            ->where('merchant_id', $merchant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new IdempotencyConflictException();
            }

            return [
                'data'        => $this->serialize($existing),
                'status_code' => 200,
            ];
        }

        // --- 2. Ceiling check + insert, atomically ---
        //
        // The lockForUpdate() below serializes concurrent refunds against
        // the same payment intent. The ceiling SUM is re-run *after* the
        // lock is acquired, so any refund committed by a concurrent
        // request is now visible. Without the lock, two simultaneous
        // requests would each see "0 already refunded" and both insert,
        // resulting in an over-refund (see RefundCeilingConcurrencyTest).
        return DB::transaction(function () use (
            $merchant,
            $payment,
            $amount,
            $reason,
            $idempotencyKey,
            $requestHash
        ) {
            $lockedPayment = PaymentIntent::query()
                ->where('id', $payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $alreadyRefunded = (int) Refund::query()
                ->where('payment_intent_id', $lockedPayment->id)
                ->whereIn('status', ['pending', 'succeeded'])
                ->sum('amount');

            if ($alreadyRefunded + $amount > $lockedPayment->amount) {
                throw new RefundNotAllowedException(
                    'Refund amount exceeds remaining refundable amount.'
                );
            }

            // --- 3. Insert. The unique index on (merchant_id, idempotency_key)
            //        guards against two concurrent requests that carry the
            //        same key. ---
            try {
                $refund = Refund::create([
                    'payment_intent_id' => $lockedPayment->id,
                    'merchant_id'       => $merchant->id,
                    'amount'            => $amount,
                    'currency'          => $lockedPayment->currency, // always from the PI
                    'status'            => 'pending',
                    'reason'            => $reason,
                    'idempotency_key'   => $idempotencyKey,
                    'request_hash'      => $requestHash,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // A concurrent request won the race with the same key.
                // Re-read and treat as a replay.
                $existing = Refund::query()
                    ->where('merchant_id', $merchant->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();

                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new IdempotencyConflictException();
                }

                return [
                    'data'        => $this->serialize($existing),
                    'status_code' => 200,
                ];
            }

            return [
                'data'        => $this->serialize($refund),
                'status_code' => 201,
            ];
        });
    }

    private function requestHash(string $paymentIntentId, int $amount, ?string $reason): string
    {
        return hash('sha256', json_encode([
            'payment_intent_id' => $paymentIntentId,
            'amount'            => $amount,
            'reason'            => $reason,
        ]));
    }

    /**
     * The public API shape of a Refund. Only fields here are ever returned.
     * Internal columns (request_hash, idempotency_key, merchant_id) are
     * deliberately absent — this is an allow-list, not a deny-list.
     */
    private function serialize(Refund $refund): array
    {
        return [
            'id'                => $refund->id,
            'payment_intent_id' => $refund->payment_intent_id,
            'amount'            => $refund->amount,
            'currency'          => $refund->currency,
            'status'            => $refund->status,
            'reason'            => $refund->reason,
            'created_at'        => $refund->created_at?->toIso8601String(),
        ];
    }
}