<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'amount' => 10000,
            'currency' => 'PKR',
            'status' => 'completed',
        ];
    }
}