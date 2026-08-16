<?php

namespace Database\Factories;

use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'payment_intent_id' => PaymentIntent::factory(),
            'processor' => 'stripe',
            'amount' => 1000, // Safe default fallback for make()
            'currency' => 'GBP',
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

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (PaymentAttempt $attempt) {
            // Automatically sync amount and currency from the generated PaymentIntent
            if ($attempt->paymentIntent) {
                $attempt->update([
                    'amount' => $attempt->paymentIntent->amount,
                    'currency' => $attempt->paymentIntent->currency,
                ]);
            }
        });
    }
}