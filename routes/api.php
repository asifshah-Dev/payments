<?php

use App\Models\Merchant;
use App\Models\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Payment Intent API
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    Route::post('/payment-intents', function (Request $request) {

        /*
        |--------------------------------------------------------------------------
        | 1. Authenticate merchant
        |--------------------------------------------------------------------------
        |
        | Test contract:
        | Authorization: Bearer test-token-{merchant_id}
        |
        */

        $authorization = $request->header('Authorization');

        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $token = trim(substr($authorization, 7));

        if (!str_starts_with($token, 'test-token-')) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $merchantId = substr($token, strlen('test-token-'));

        if (!Str::isUuid($merchantId)) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $merchant = Merchant::where('id', $merchantId)
            ->where('status', 'active')
            ->first();

        if (!$merchant) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Validate Idempotency-Key
        |--------------------------------------------------------------------------
        */

        $idempotencyKey = trim(
            (string) $request->header('Idempotency-Key')
        );

        if ($idempotencyKey === '') {
            return response()->json([
                'message' => 'The Idempotency-Key header is required.',
            ], 422);
        }

        if (!Str::isUuid($idempotencyKey)) {
            return response()->json([
                'message' => 'The Idempotency-Key must be a valid UUID.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Validate request body
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'amount' => [
                'required',
                'integer',
                'min:1',
            ],

            'currency' => [
                'required',
                'string',
                'size:3',
                'in:USD,EUR,GBP,PKR,AED,SAR,JPY,CAD,AUD,CHF,CNY,INR',
            ],

            'description' => [
                'nullable',
                'string',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | 4. Normalize request
        |--------------------------------------------------------------------------
        */

        $normalizedPayload = [
            'amount' => $validated['amount'],
            'currency' => strtoupper($validated['currency']),
            'description' => $validated['description'] ?? null,
        ];

        /*
        |--------------------------------------------------------------------------
        | 5. Generate deterministic request fingerprint
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | This must match the test:
        |
        | json_encode([
        |     'amount' => ...,
        |     'currency' => ...,
        |     'description' => ...
        | ])
        |
        */

        $requestHash = hash(
            'sha256',
            json_encode($normalizedPayload)
        );

        /*
        |--------------------------------------------------------------------------
        | 6. Idempotency + PaymentIntent creation
        |--------------------------------------------------------------------------
        */

        return DB::transaction(function () use (
            $merchant,
            $idempotencyKey,
            $requestHash,
            $normalizedPayload
        ) {

            /*
            |--------------------------------------------------------------------------
            | Find an existing PaymentIntent for this merchant + idempotency key
            |--------------------------------------------------------------------------
            */

            $existing = PaymentIntent::where(
                'merchant_id',
                $merchant->id
            )
                ->where(
                    'idempotency_key',
                    $idempotencyKey
                )
                ->lockForUpdate()
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Existing request
            |--------------------------------------------------------------------------
            */

            if ($existing) {

                /*
                |--------------------------------------------------------------------------
                | Same key but different request
                |--------------------------------------------------------------------------
                */

                if ($existing->request_hash !== $requestHash) {
                    return response()->json([
                        'message' =>
                            'Idempotency key already used with different parameters.',
                    ], 409);
                }

                /*
                |--------------------------------------------------------------------------
                | Same key + same request
                |--------------------------------------------------------------------------
                |
                | Return the SAME payment intent.
                |
                */

                return response()->json([
                    'id' => $existing->id,
                    'amount' => $existing->amount,
                    'currency' => $existing->currency,
                    'status' => $existing->status,
                    'description' => $existing->description,
                ], 201);
            }

            /*
            |--------------------------------------------------------------------------
            | 7. Create PaymentIntent
            |--------------------------------------------------------------------------
            */

            $paymentIntent = PaymentIntent::create([
                'merchant_id' => $merchant->id,
                'amount' => $normalizedPayload['amount'],
                'currency' => $normalizedPayload['currency'],
                'description' => $normalizedPayload['description'],
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 8. Return only public business fields
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'status' => $paymentIntent->status,
                'description' => $paymentIntent->description,
            ], 201);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Get Payment Intent
    |--------------------------------------------------------------------------
    */

    Route::get('/payment-intents/{id}', function (
        Request $request,
        string $id
    ) {

        /*
        |--------------------------------------------------------------------------
        | Authenticate merchant
        |--------------------------------------------------------------------------
        */

        $authorization = $request->header('Authorization');

        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $token = trim(substr($authorization, 7));

        if (!str_starts_with($token, 'test-token-')) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $merchantId = substr($token, strlen('test-token-'));

        if (!Str::isUuid($merchantId)) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $merchant = Merchant::where('id', $merchantId)
            ->where('status', 'active')
            ->first();

        if (!$merchant) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Merchant isolation
        |--------------------------------------------------------------------------
        */

        $paymentIntent = PaymentIntent::where('id', $id)
            ->where('merchant_id', $merchant->id)
            ->first();

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
    });
});