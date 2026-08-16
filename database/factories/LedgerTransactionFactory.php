<?php

namespace Database\Factories;

use App\Models\LedgerTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerTransaction>
 */
class LedgerTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(['debit', 'credit']),
            'payment_attempt_id' => null,
            'reference_type' => null,
            'reference_id' => null,
            'description' => $this->faker->sentence(),
            'posted_at' => now(),
        ];
    }
}
