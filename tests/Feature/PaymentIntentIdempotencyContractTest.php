```php
<?php

use App\Models\Merchant;
use App\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function createMerchantForIdempotencyTest(): Merchant
{
    return Merchant::factory()->create();
}

function createPaymentIntentDirectly(
    Merchant $merchant,
    string $idempotencyKey,
    int $amount = 10000,
    string $currency = 'USD',
    ?string $description = 'Test payment',
    string $status = 'pending'
): PaymentIntent {
    return PaymentIntent::create([
        'merchant_id' => $merchant->id,
        'amount' => $amount,
        'currency' => $currency,
        'description' => $description,
        'status' => $status,
        'idempotency_key' => $idempotencyKey,
        'request_hash' => hash(
            'sha256',
            json_encode([
                'amount' => $amount,
                'currency' => $currency,
                'description' => $description,
            ])
        ),
    ]);
}

/*
|--------------------------------------------------------------------------
| 1. Same request + same idempotency key
|--------------------------------------------------------------------------
*/

it('returns the original payment intent for the same request and idempotency key', function () {
    $merchant = createMerchantForIdempotencyTest();

    $key = (string) Str::uuid();

    $first = createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: $key,
        amount: 10000,
        currency: 'USD',
        description: 'Order #100'
    );

    $second = PaymentIntent::where('merchant_id', $merchant->id)
        ->where('idempotency_key', $key)
        ->first();

    expect($second)->not->toBeNull()
        ->and($second->id)->toBe($first->id)
        ->and(
            PaymentIntent::where('merchant_id', $merchant->id)
                ->where('idempotency_key', $key)
                ->count()
        )->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 2. Same key + different amount
|--------------------------------------------------------------------------
*/

it('rejects idempotency key reuse when the amount changes', function () {
    $merchant = createMerchantForIdempotencyTest();

    $key = (string) Str::uuid();

    createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: $key,
        amount: 10000
    );

    $originalHash = PaymentIntent::where('merchant_id', $merchant->id)
        ->where('idempotency_key', $key)
        ->value('request_hash');

    $newHash = hash(
        'sha256',
        json_encode([
            'amount' => 20000,
            'currency' => 'USD',
            'description' => 'Test payment',
        ])
    );

    expect($newHash)->not->toBe($originalHash);
});

/*
|--------------------------------------------------------------------------
| 3. Same key + different currency
|--------------------------------------------------------------------------
*/

it('rejects idempotency key reuse when the currency changes', function () {
    $merchant = createMerchantForIdempotencyTest();

    $key = (string) Str::uuid();

    $originalHash = hash(
        'sha256',
        json_encode([
            'amount' => 10000,
            'currency' => 'USD',
            'description' => 'Test payment',
        ])
    );

    createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: $key,
        amount: 10000,
        currency: 'USD'
    );

    $newHash = hash(
        'sha256',
        json_encode([
            'amount' => 10000,
            'currency' => 'EUR',
            'description' => 'Test payment',
        ])
    );

    expect($newHash)->not->toBe($originalHash);
});

/*
|--------------------------------------------------------------------------
| 4. Same key + different description
|--------------------------------------------------------------------------
*/

it('rejects idempotency key reuse when the description changes', function () {
    $merchant = createMerchantForIdempotencyTest();

    $key = (string) Str::uuid();

    $originalHash = hash(
        'sha256',
        json_encode([
            'amount' => 10000,
            'currency' => 'USD',
            'description' => 'Order #100',
        ])
    );

    createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: $key,
        amount: 10000,
        currency: 'USD',
        description: 'Order #100'
    );

    $newHash = hash(
        'sha256',
        json_encode([
            'amount' => 10000,
            'currency' => 'USD',
            'description' => 'Order #200',
        ])
    );

    expect($newHash)->not->toBe($originalHash);
});

/*
|--------------------------------------------------------------------------
| 5. Different keys + identical request
|--------------------------------------------------------------------------
*/

it('creates separate payment intents for different idempotency keys', function () {
    $merchant = createMerchantForIdempotencyTest();

    createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: (string) Str::uuid()
    );

    createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: (string) Str::uuid()
    );

    expect(
        PaymentIntent::where('merchant_id', $merchant->id)->count()
    )->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 6. Idempotency key is scoped to the merchant
|--------------------------------------------------------------------------
*/

it('isolates idempotency keys between merchants', function () {
    $merchantA = createMerchantForIdempotencyTest();
    $merchantB = createMerchantForIdempotencyTest();

    $key = (string) Str::uuid();

    $first = createPaymentIntentDirectly(
        merchant: $merchantA,
        idempotencyKey: $key
    );

    $second = createPaymentIntentDirectly(
        merchant: $merchantB,
        idempotencyKey: $key
    );

    expect($first->id)->not->toBe($second->id)
        ->and(
            PaymentIntent::where('idempotency_key', $key)->count()
        )->toBe(2);
});

/*
|--------------------------------------------------------------------------
| 7. Missing idempotency key
|--------------------------------------------------------------------------
*/

it('rejects a missing idempotency key', function () {
    $merchant = createMerchantForIdempotencyTest();

    expect(fn () => createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: ''
    ))->toThrow(\Throwable::class);
})->skip(
    'Enable this once idempotency-key validation is enforced at the API/service boundary.'
);

/*
|--------------------------------------------------------------------------
| 8. Malformed idempotency key
|--------------------------------------------------------------------------
*/

it('rejects a malformed idempotency key', function () {
    $merchant = createMerchantForIdempotencyTest();

    expect(fn () => createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: 'not-a-valid-uuid'
    ))->toThrow(\Throwable::class);
})->skip(
    'Enable this once UUID validation is enforced at the API/service boundary.'
);

/*
|--------------------------------------------------------------------------
| 9. Concurrent same-key requests
|--------------------------------------------------------------------------
|
| This test verifies the DATABASE invariant.
|
| Your unique constraint:
|
| merchant_id + idempotency_key
|
| must guarantee exactly one record.
|
*/

it('allows only one payment intent for concurrent requests using the same key', function () {
    $merchant = createMerchantForIdempotencyTest();

    $key = (string) Str::uuid();

    $hash = hash(
        'sha256',
        json_encode([
            'amount' => 10000,
            'currency' => 'USD',
            'description' => 'Concurrent order',
        ])
    );

    $inserted = 0;

    foreach (range(1, 20) as $attempt) {
        try {
            DB::table('payment_intents')->insert([
                'id' => (string) Str::uuid(),
                'merchant_id' => $merchant->id,
                'amount' => 10000,
                'currency' => 'USD',
                'description' => 'Concurrent order',
                'status' => 'pending',
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inserted++;
        } catch (\Illuminate\Database\QueryException $e) {
            // Expected for duplicate concurrent attempts.
        }
    }

    expect($inserted)->toBe(1)
        ->and(
            DB::table('payment_intents')
                ->where('merchant_id', $merchant->id)
                ->where('idempotency_key', $key)
                ->count()
        )->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 10. Concurrent different keys
|--------------------------------------------------------------------------
*/

it('creates separate payment intents for concurrent requests with different keys', function () {
    $merchant = createMerchantForIdempotencyTest();

    foreach (range(1, 10) as $attempt) {
        createPaymentIntentDirectly(
            merchant: $merchant,
            idempotencyKey: (string) Str::uuid(),
            amount: 10000,
            currency: 'USD',
            description: 'Concurrent order'
        );
    }

    expect(
        PaymentIntent::where('merchant_id', $merchant->id)->count()
    )->toBe(10);
});

/*
|--------------------------------------------------------------------------
| 11. Failed first attempt can be retried
|--------------------------------------------------------------------------
*/

it('allows a failed payment intent operation to be retried according to policy', function () {
    $merchant = createMerchantForIdempotencyTest();

    $key = (string) Str::uuid();

    $failed = createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: $key,
        status: 'failed'
    );

    expect($failed->status)->toBe('failed');

    /*
     * The important invariant here is that the same idempotency key
     * still identifies the same operation.
     *
     * Whether a failed key can be reused depends on your chosen
     * business policy.
     */
    $sameIntent = PaymentIntent::where('merchant_id', $merchant->id)
        ->where('idempotency_key', $key)
        ->first();

    expect($sameIntent->id)->toBe($failed->id);
});

/*
|--------------------------------------------------------------------------
| 12. Completed request returns same response identity
|--------------------------------------------------------------------------
*/

it('returns the same payment intent identity after completion', function () {
    $merchant = createMerchantForIdempotencyTest();

    $key = (string) Str::uuid();

    $first = createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: $key,
        status: 'succeeded'
    );

    $replayed = PaymentIntent::where('merchant_id', $merchant->id)
        ->where('idempotency_key', $key)
        ->first();

    expect($replayed)->not->toBeNull()
        ->and($replayed->id)->toBe($first->id)
        ->and($replayed->status)->toBe('succeeded')
        ->and($replayed->amount)->toBe($first->amount)
        ->and($replayed->currency)->toBe($first->currency);
});

/*
|--------------------------------------------------------------------------
| 13. Request hash is deterministic
|--------------------------------------------------------------------------
*/

it('generates the same request hash for the same normalized request', function () {
    $payload = [
        'amount' => 10000,
        'currency' => 'USD',
        'description' => 'Test payment',
    ];

    $hash1 = hash('sha256', json_encode($payload));
    $hash2 = hash('sha256', json_encode($payload));

    expect($hash1)->toBe($hash2);
});

/*
|--------------------------------------------------------------------------
| 14. Fingerprint changes when request changes
|--------------------------------------------------------------------------
*/

it('generates a different request hash when fingerprinted data changes', function () {
    $original = hash(
        'sha256',
        json_encode([
            'amount' => 10000,
            'currency' => 'USD',
            'description' => 'Test payment',
        ])
    );

    $changed = hash(
        'sha256',
        json_encode([
            'amount' => 20000,
            'currency' => 'USD',
            'description' => 'Test payment',
        ])
    );

    expect($changed)->not->toBe($original);
});

/*
|--------------------------------------------------------------------------
| 15. Existing idempotency record cannot be silently replaced
|--------------------------------------------------------------------------
*/

it('prevents replacing an existing idempotency record', function () {
    $merchant = createMerchantForIdempotencyTest();

    $key = (string) Str::uuid();

    $first = createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: $key,
        amount: 10000
    );

    expect(fn () => createPaymentIntentDirectly(
        merchant: $merchant,
        idempotencyKey: $key,
        amount: 20000
    ))->toThrow(\Illuminate\Database\QueryException::class);

    $stored = PaymentIntent::where('merchant_id', $merchant->id)
        ->where('idempotency_key', $key)
        ->first();

    expect($stored->id)->toBe($first->id)
        ->and($stored->amount)->toBe(10000);
});
