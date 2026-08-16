<?php

namespace Database\Factories;

use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
{
    $paymentIntent = \App\Models\PaymentIntent::factory()->create();

    return [
        'id' => $this->faker->uuid(),
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'amount' => $paymentIntent->amount,
        'currency' => $paymentIntent->currency,
        'status' => $this->faker->randomElement([
            'pending',
            'processing',
            'succeeded',
            'failed',
        ]),
        'processor_reference_id' => null,
        'failure_code' => null,
        'failure_message' => null,
    ];
}
}