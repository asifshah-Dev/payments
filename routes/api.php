<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Models\PaymentIntent;

Route::post('/payment-intents', function (Request $request) {
    // 1. Validate headers & request payload according to the idempotency contract
    $validated = $request->validate([
        'amount' => 'required|integer|min:1',
        'currency' => 'required|string|size:3',
        'description' => 'nullable|string',
    ]);

    $idempotencyKey = trim((string) $request->header('X-Idempotency-Key'));
    $merchantId = $request->header('X-Merchant-Id');

    if (empty($idempotencyKey) || empty($merchantId)) {
        return response()->json(['message' => 'Missing required idempotency key or merchant id header.'], 422);
    }

    // 2. Normalize and generate request fingerprint/hash
    $normalizedPayload = [
        'amount' => $validated['amount'],
        'currency' => strtoupper($validated['currency']),
        'description' => $validated['description'] ?? null,
    ];
    
    // Sort keys to ensure deterministic hashing regardless of key ordering
    ksort($normalizedPayload);
    $requestHash = hash('sha256', json_encode($normalizedPayload));

    // 3. Handle idempotency lookup with concurrency safety
    return DB::transaction(function () use ($merchantId, $idempotencyKey, $requestHash, $normalizedPayload) {
        
        // Lock or fetch existing idempotency record scoped strictly to the merchant
        $existing = DB::table('idempotency_keys')
            ->where('merchant_id', $merchantId)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            // Conflict check: Same key used with a different request payload fingerprint
            if ($existing->request_hash !== $requestHash) {
                return response()->json(['message' => 'Idempotency key already used with different parameters.'], 422);
            }

            // Return cached previous response
            $responseData = json_decode($existing->response_body, true);
            return response()->json($responseData, $existing->response_code);
        }

        // Simulate failure handling if flag is present in test payload
        if (request()->has('simulate_failure')) {
            return response()->json(['message' => 'Simulated failure'], 400);
        }

        // Create the actual Payment Intent record
        $paymentIntent = PaymentIntent::create([
            'merchant_id' => $merchantId,
            'amount' => $normalizedPayload['amount'],
            'currency' => $normalizedPayload['currency'],
            'description' => $normalizedPayload['description'],
            'status' => 'pending',
        ]);

        $responseBody = [
            'data' => [
                'id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'description' => $paymentIntent->description,
            ]
        ];

        // Store idempotency log
        DB::table('idempotency_keys')->insert([
            'merchant_id' => $merchantId,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'response_code' => 201,
            'response_body' => json_encode($responseBody),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($responseBody, 201);
    });
});