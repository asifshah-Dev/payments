<?php

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Services\PaymentIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('creates a payment intent', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $paymentIntent = $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: fake()->uuid(),
    );

    expect($paymentIntent)
        ->toBeInstanceOf(PaymentIntent::class)
        ->amount->toBe(5000)
        ->currency->toBe('USD')
        ->description->toBe('Test payment')
        ->status->toBe('pending')
        ->merchant_id->toBe($merchant->id);

    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    $this->assertDatabaseHas('payment_intents', [
        'id' => $paymentIntent->id,
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'status' => 'pending',
    ]);
});
test('returns the existing payment intent for the same idempotency key and same request', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $idempotencyKey = fake()->uuid();

    $first = $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    );

    $second = $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    );

    expect($second->id)->toBe($first->id);

    expect(PaymentIntent::count())->toBe(1);
});
test('rejects the same idempotency key when the request is different', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $idempotencyKey = fake()->uuid();

    $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    );

    expect(fn () => $service->create(
        merchantId: $merchant->id,
        amount: 7000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    ))->toThrow(Exception::class);

    expect(PaymentIntent::count())->toBe(1);
});
test('allows the same idempotency key for different merchants', function () {
    $merchantOne = Merchant::factory()->create();
    $merchantTwo = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $idempotencyKey = fake()->uuid();

    $first = $service->create(
        merchantId: $merchantOne->id,
        amount: 5000,
        currency: 'USD',
        description: 'Merchant one payment',
        idempotencyKey: $idempotencyKey,
    );

    $second = $service->create(
        merchantId: $merchantTwo->id,
        amount: 5000,
        currency: 'USD',
        description: 'Merchant two payment',
        idempotencyKey: $idempotencyKey,
    );

    expect($first->id)->not->toBe($second->id);

    expect(PaymentIntent::count())->toBe(2);
});
test('rejects zero amount', function () {
    
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    expect(fn () => $service->create(
        merchantId: $merchant->id,
        amount: 0,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: fake()->uuid(),
    ))->toThrow(Exception::class);

    expect(PaymentIntent::count())->toBe(0);
});
test('rejects negative amount', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    expect(fn () => $service->create(
        merchantId: $merchant->id,
        amount: -100,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: fake()->uuid(),
    ))->toThrow(\InvalidArgumentException::class);

    expect(PaymentIntent::count())->toBe(0);
});
test('rejects invalid currency', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    expect(fn () => $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'XYZ',
        description: 'Test payment',
        idempotencyKey: fake()->uuid(),
    ))->toThrow(\InvalidArgumentException::class);

    expect(PaymentIntent::count())->toBe(0);
});
test('normalizes currency to uppercase', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $paymentIntent = $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'usd',
        description: 'Test payment',
        idempotencyKey: fake()->uuid(),
    );

    expect($paymentIntent->currency)->toBe('USD');

    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    $this->assertDatabaseHas('payment_intents', [
        'id' => $paymentIntent->id,
        'currency' => 'USD',
    ]);
});
test('rejects an empty idempotency key', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    expect(fn () => $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: '',
    ))->toThrow(\InvalidArgumentException::class);

    expect(PaymentIntent::count())->toBe(0);
});
test('rejects an excessively long idempotency key', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $idempotencyKey = str_repeat('a', 256);

    expect(fn () => $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    ))->toThrow(\InvalidArgumentException::class);

    expect(PaymentIntent::count())->toBe(0);
});
test('allows the minimum positive amount', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $paymentIntent = $service->create(
        merchantId: $merchant->id,
        amount: 1,
        currency: 'USD',
        description: 'Minimum payment',
        idempotencyKey: fake()->uuid(),
    );

    expect($paymentIntent->amount)->toBe(1);

    expect(PaymentIntent::count())->toBe(1);
});
test('rejects same idempotency key when only amount changes', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $idempotencyKey = fake()->uuid();

    $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    );

    expect(fn () => $service->create(
        merchantId: $merchant->id,
        amount: 6000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    ))->toThrow(\Exception::class);

    expect(PaymentIntent::count())->toBe(1);
});
test('rejects same idempotency key when only currency changes', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $idempotencyKey = fake()->uuid();

    $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    );

    expect(fn () => $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'GBP',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    ))->toThrow(\Exception::class);

    expect(PaymentIntent::count())->toBe(1);
});
test('rejects same idempotency key when only description changes', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $idempotencyKey = fake()->uuid();

    $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Original payment',
        idempotencyKey: $idempotencyKey,
    );

    expect(fn () => $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Different payment',
        idempotencyKey: $idempotencyKey,
    ))->toThrow(\Exception::class);

    expect(PaymentIntent::count())->toBe(1);
});
test('treats currency case differences as the same request', function () {
    $merchant = Merchant::factory()->create();

    $service = new PaymentIntentService();

    $idempotencyKey = fake()->uuid();

    $first = $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'usd',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    );

    $second = $service->create(
        merchantId: $merchant->id,
        amount: 5000,
        currency: 'USD',
        description: 'Test payment',
        idempotencyKey: $idempotencyKey,
    );

    expect($second->id)->toBe($first->id);
    expect(PaymentIntent::count())->toBe(1);
});