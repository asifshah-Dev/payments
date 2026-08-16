<?php

namespace Database\Factories;

use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\LedgerTransaction;
use App\Models\LedgerAccount;
/**
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
   public function definition(): array
{
    return [
        'ledger_transaction_id' => LedgerTransaction::factory(),
        'ledger_account_id' => LedgerAccount::factory(),
        'type' => fake()->randomElement(['debit', 'credit']),
        'amount' => fake()->numberBetween(100, 10000000),
        'currency' => fake()->randomElement(['PKR', 'USD', 'GBP', 'EUR']),
    ];
}
}
