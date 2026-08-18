<?php

namespace Database\Factories;

use App\Models\PaymentIntent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentIntent>
 */
class PaymentIntentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
{
    return [
        'merchant_id' => \App\Models\Merchant::factory(),
        'amount' => 1000,
        'currency' => 'GBP',
        'description' => 'Test payment',
        'status' => 'pending',
        'idempotency_key' => $this->faker->uuid(),
        'request_hash' => $this->faker->sha256(),
    ];
}
}
