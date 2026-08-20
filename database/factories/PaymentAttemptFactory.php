<?php

namespace Database\Factories;

use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'payment_intent_id' => PaymentIntent::factory(),
            'processor' => 'stripe',
            'amount' => 1000,
            'currency' => 'GBP',
            'status' => 'pending',
            'processor_reference_id' => null,
            'failure_code' => null,
            'failure_message' => null,
        ];
    }
}