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
'type' => 'payment', // Use a valid transaction type here            'payment_attempt_id' => null,
            'reference_type' => null,
            'reference_id' => null,
            'description' => $this->faker->sentence(),
            'posted_at' => now(),
        ];
    }
}
