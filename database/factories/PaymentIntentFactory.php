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
            'amount' => $this->faker->numberBetween(100, 10000),
            'currency' => $this->faker->randomElement([
    'USD',
    'EUR',
    'GBP',
    'PKR',
]),
            'description' => $this->faker->sentence(),
            'status' => $this->faker->randomElement(['pending', 'processing', 'succeeded', 'failed', 'cancelled']),
            'idempotency_key' => $this->faker->uuid(),
            'request_hash' => $this->faker->sha256(),
        ];
    }
}
