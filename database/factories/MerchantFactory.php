<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            
            'name' => $this->faker->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'status' => $this->faker->randomElement(['active', 'inactive','suspended']),
        ];
    }
}
