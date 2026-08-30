<?php

use App\Models\Merchant;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
|
| Change this if your actual API route is different.
|
*/

const PAYMENT_INTENT_ENDPOINT = '/api/v1/payment-intents';

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function apiMerchant(): Merchant
{
    return Merchant::factory()->create([
        'status' => 'active',
    ]);
}

/*
|--------------------------------------------------------------------------
| Authentication helper
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Replace this implementation with however your application authenticates
| merchants.
|
| If your middleware already identifies the merchant using a bearer token,
| adapt this helper to create the appropriate token.
|
*/

function authenticatedHeaders(Merchant $merchant, ?string $idempotencyKey = null): array
{
    $headers = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer test-token-' . $merchant->id,
    ];

    if ($idempotencyKey !== null) {
        $headers['Idempotency-Key'] = $idempotencyKey;
    }

    return $headers;
}

function validPaymentPayload(array $overrides = []): array
{
    return array_merge([
        'amount' => 10000,
        'currency' => 'USD',
        'description' => 'Test payment',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| 1. Valid request
|--------------------------------------------------------------------------
*/

it('creates a payment intent with a valid authenticated request', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, $key)
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $response->assertStatus(201);

    $response->assertJsonStructure([
        'id',
        'amount',
        'currency',
        'status',
    ]);

    $this->assertDatabaseHas('payment_intents', [
        'merchant_id' => $merchant->id,
        'amount' => 10000,
        'currency' => 'USD',
        'idempotency_key' => $key,
    ]);
});

/*
|--------------------------------------------------------------------------
| 2. Missing Authorization
|--------------------------------------------------------------------------
*/

it('rejects a request without authentication', function () {
    $key = (string) Str::uuid();

    $response = $this->withHeaders([
        'Accept' => 'application/json',
        'Idempotency-Key' => $key,
    ])->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $response->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| 3. Invalid authentication
|--------------------------------------------------------------------------
*/

it('rejects invalid authentication credentials', function () {
    $key = (string) Str::uuid();

    $response = $this->withHeaders([
        'Accept' => 'application/json',
        'Authorization' => 'Bearer completely-invalid-token',
        'Idempotency-Key' => $key,
    ])->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $response->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| 4. Missing Idempotency-Key
|--------------------------------------------------------------------------
*/

it('rejects a request without an idempotency key', function () {
    $merchant = apiMerchant();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant)
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 5. Malformed Idempotency-Key
|--------------------------------------------------------------------------
*/

it('rejects a malformed idempotency key', function () {
    $merchant = apiMerchant();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, 'not-a-valid-uuid')
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 6. Missing amount
|--------------------------------------------------------------------------
*/

it('rejects a request without amount', function () {
    $merchant = apiMerchant();

    $payload = validPaymentPayload();
    unset($payload['amount']);

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, (string) Str::uuid())
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        $payload
    );

    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 7. Zero amount
|--------------------------------------------------------------------------
*/

it('rejects an amount of zero', function () {
    $merchant = apiMerchant();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, (string) Str::uuid())
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload([
            'amount' => 0,
        ])
    );

    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 8. Negative amount
|--------------------------------------------------------------------------
*/

it('rejects a negative amount', function () {
    $merchant = apiMerchant();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, (string) Str::uuid())
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload([
            'amount' => -100,
        ])
    );

    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 9. Invalid currency
|--------------------------------------------------------------------------
*/

it('rejects an invalid currency', function () {
    $merchant = apiMerchant();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, (string) Str::uuid())
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload([
            'currency' => 'XYZ',
        ])
    );

    /*
     * If XYZ is allowed by your ISO-4217 allowlist, replace this
     * with a definitely unsupported value.
     */
    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 10. Currency longer than three characters
|--------------------------------------------------------------------------
*/

it('rejects a currency longer than three characters', function () {
    $merchant = apiMerchant();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, (string) Str::uuid())
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload([
            'currency' => 'USDD',
        ])
    );

    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| 11. Missing description
|--------------------------------------------------------------------------
|
| This test assumes description is optional because your migration
| previously defined it as nullable.
|
*/

it('allows a payment intent without a description', function () {
    $merchant = apiMerchant();

    $payload = validPaymentPayload();
    unset($payload['description']);

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, (string) Str::uuid())
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        $payload
    );

    $response->assertStatus(201);
});

/*
|--------------------------------------------------------------------------
| 12. Merchant ID supplied in body must not control ownership
|--------------------------------------------------------------------------
*/

it('does not trust merchant id supplied in the request body', function () {
    $merchantA = apiMerchant();
    $merchantB = apiMerchant();

    $key = (string) Str::uuid();

    $response = $this->withHeaders(
        authenticatedHeaders($merchantA, $key)
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload([
            'merchant_id' => $merchantB->id,
        ])
    );

    $response->assertStatus(201);

    $intent = PaymentIntent::where(
        'idempotency_key',
        $key
    )->first();

    expect($intent)->not->toBeNull()
        ->and($intent->merchant_id)->toBe($merchantA->id);
});

/*
|--------------------------------------------------------------------------
| 13. Merchant identity comes from authentication
|--------------------------------------------------------------------------
*/

it('assigns the authenticated merchant to the payment intent', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, $key)
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $response->assertStatus(201);

    $intent = PaymentIntent::where(
        'idempotency_key',
        $key
    )->first();

    expect($intent->merchant_id)->toBe($merchant->id);
});

/*
|--------------------------------------------------------------------------
| 14. Same request + same key
|--------------------------------------------------------------------------
*/

it('returns the same payment intent for repeated identical requests', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $headers = authenticatedHeaders($merchant, $key);
    $payload = validPaymentPayload();

    $first = $this->withHeaders($headers)
        ->postJson(PAYMENT_INTENT_ENDPOINT, $payload);

    $first->assertStatus(201);

    $firstId = $first->json('id');

    $second = $this->withHeaders($headers)
        ->postJson(PAYMENT_INTENT_ENDPOINT, $payload);

    $second->assertStatus(201);

    expect($second->json('id'))->toBe($firstId);

    expect(
        PaymentIntent::where('merchant_id', $merchant->id)
            ->where('idempotency_key', $key)
            ->count()
    )->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 15. Same key + different amount
|--------------------------------------------------------------------------
*/

it('rejects idempotency key reuse with a different amount', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $headers = authenticatedHeaders($merchant, $key);

    $this->withHeaders($headers)
        ->postJson(
            PAYMENT_INTENT_ENDPOINT,
            validPaymentPayload([
                'amount' => 10000,
            ])
        )
        ->assertStatus(201);

    $response = $this->withHeaders($headers)
        ->postJson(
            PAYMENT_INTENT_ENDPOINT,
            validPaymentPayload([
                'amount' => 20000,
            ])
        );

    $response->assertStatus(409);

    expect(
        PaymentIntent::where('merchant_id', $merchant->id)
            ->where('idempotency_key', $key)
            ->count()
    )->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 16. Same key + different currency
|--------------------------------------------------------------------------
*/

it('rejects idempotency key reuse with a different currency', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $headers = authenticatedHeaders($merchant, $key);

    $this->withHeaders($headers)
        ->postJson(
            PAYMENT_INTENT_ENDPOINT,
            validPaymentPayload([
                'currency' => 'USD',
            ])
        )
        ->assertStatus(201);

    $response = $this->withHeaders($headers)
        ->postJson(
            PAYMENT_INTENT_ENDPOINT,
            validPaymentPayload([
                'currency' => 'EUR',
            ])
        );

    $response->assertStatus(409);
});

/*
|--------------------------------------------------------------------------
| 17. Same key + different description
|--------------------------------------------------------------------------
*/

it('rejects idempotency key reuse with a different description', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $headers = authenticatedHeaders($merchant, $key);

    $this->withHeaders($headers)
        ->postJson(
            PAYMENT_INTENT_ENDPOINT,
            validPaymentPayload([
                'description' => 'Order A',
            ])
        )
        ->assertStatus(201);

    $response = $this->withHeaders($headers)
        ->postJson(
            PAYMENT_INTENT_ENDPOINT,
            validPaymentPayload([
                'description' => 'Order B',
            ])
        );

    $response->assertStatus(409);
});

/*
|--------------------------------------------------------------------------
| 18. Different idempotency key
|--------------------------------------------------------------------------
*/

it('creates a new payment intent when the idempotency key changes', function () {
    $merchant = apiMerchant();

    $first = $this->withHeaders(
        authenticatedHeaders($merchant, (string) Str::uuid())
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $second = $this->withHeaders(
        authenticatedHeaders($merchant, (string) Str::uuid())
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $first->assertStatus(201);
    $second->assertStatus(201);

    expect($first->json('id'))
        ->not->toBe($second->json('id'));

    expect(
        PaymentIntent::where('merchant_id', $merchant->id)->count()
    )->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 19. Merchant isolation
|--------------------------------------------------------------------------
*/

it('prevents one merchant from receiving another merchants payment intent', function () {
    $merchantA = apiMerchant();
    $merchantB = apiMerchant();

    $key = (string) Str::uuid();

    $responseA = $this->withHeaders(
        authenticatedHeaders($merchantA, $key)
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $responseA->assertStatus(201);

    $intentId = $responseA->json('id');

    /*
     * Adjust this endpoint if your API uses a different URL.
     */
    $responseB = $this->withHeaders(
        authenticatedHeaders($merchantB)
    )->getJson(
        PAYMENT_INTENT_ENDPOINT . '/' . $intentId
    );

    expect($responseB->status())->toBeIn([403, 404]);
});

/*
|--------------------------------------------------------------------------
| 20. Correct request hash
|--------------------------------------------------------------------------
*/

it('stores the expected request fingerprint', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $payload = validPaymentPayload();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, $key)
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        $payload
    );

    $response->assertStatus(201);

    $expectedHash = hash(
        'sha256',
        json_encode([
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'description' => $payload['description'],
        ])
    );

    $intent = PaymentIntent::where(
        'idempotency_key',
        $key
    )->first();

    expect($intent->request_hash)->toBe($expectedHash);
});

/*
|--------------------------------------------------------------------------
| 21. Response does not expose internal fields
|--------------------------------------------------------------------------
*/

it('does not expose internal idempotency or request hash fields', function () {
    $merchant = apiMerchant();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, (string) Str::uuid())
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $response->assertStatus(201);

    expect($response->json())
        ->not->toHaveKey('request_hash')
        ->not->toHaveKey('idempotency_key')
        ->not->toHaveKey('merchant_id');
});

/*
|--------------------------------------------------------------------------
| 22. Successful creation creates exactly one database record
|--------------------------------------------------------------------------
*/

it('creates exactly one payment intent for a successful request', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, $key)
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        validPaymentPayload()
    );

    $response->assertStatus(201);

    expect(
        DB::table('payment_intents')
            ->where('merchant_id', $merchant->id)
            ->where('idempotency_key', $key)
            ->count()
    )->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 23. Response contains correct business data
|--------------------------------------------------------------------------
*/

it('returns the correct payment intent business data', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $payload = validPaymentPayload([
        'amount' => 25000,
        'currency' => 'USD',
        'description' => 'Laptop purchase',
    ]);

    $response = $this->withHeaders(
        authenticatedHeaders($merchant, $key)
    )->postJson(
        PAYMENT_INTENT_ENDPOINT,
        $payload
    );

    $response->assertStatus(201)
        ->assertJson([
            'amount' => 25000,
            'currency' => 'USD',
            'status' => 'pending',
        ]);
});

/*
|--------------------------------------------------------------------------
| 24. Same completed request returns same response identity
|--------------------------------------------------------------------------
*/

it('returns the same identity when a completed request is replayed', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $headers = authenticatedHeaders($merchant, $key);

    $payload = validPaymentPayload();

    $first = $this->withHeaders($headers)
        ->postJson(PAYMENT_INTENT_ENDPOINT, $payload);

    $first->assertStatus(201);

    $intentId = $first->json('id');

    /*
     * Simulate the operation reaching a completed state.
     */
    PaymentIntent::where('id', $intentId)
        ->update([
            'status' => 'succeeded',
        ]);

    $second = $this->withHeaders($headers)
        ->postJson(PAYMENT_INTENT_ENDPOINT, $payload);

    $second->assertStatus(201);

    expect($second->json('id'))->toBe($intentId);

    expect(
        PaymentIntent::where('merchant_id', $merchant->id)
            ->where('idempotency_key', $key)
            ->count()
    )->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 25. Full API idempotency invariant
|--------------------------------------------------------------------------
*/

it('maintains the complete idempotency invariant', function () {
    $merchant = apiMerchant();

    $key = (string) Str::uuid();

    $payload = validPaymentPayload([
        'amount' => 15000,
        'currency' => 'USD',
        'description' => 'Complete invariant test',
    ]);

    $headers = authenticatedHeaders($merchant, $key);

    $first = $this->withHeaders($headers)
        ->postJson(PAYMENT_INTENT_ENDPOINT, $payload);

    $first->assertStatus(201);

    $intentId = $first->json('id');

    /*
     * Replay the exact same operation multiple times.
     */
    foreach (range(1, 10) as $attempt) {
        $response = $this->withHeaders($headers)
            ->postJson(PAYMENT_INTENT_ENDPOINT, $payload);

        $response->assertStatus(201);

        expect($response->json('id'))->toBe($intentId);
    }

    /*
     * The critical financial invariant:
     *
     * 11 HTTP requests
     *             ↓
     *       exactly 1 intent
     */
    expect(
        PaymentIntent::where('merchant_id', $merchant->id)
            ->where('idempotency_key', $key)
            ->count()
    )->toBe(1);
});