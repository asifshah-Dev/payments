<?php

namespace Tests\Feature;

use App\Models\LedgerTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTransactionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function an_unposted_transaction_can_be_created_and_modified()
    {
        // 1. Create an unposted transaction (draft)
        $transaction = LedgerTransaction::create([
            'type' => 'charge',
            'amount' => 1000,
            'currency' => 'USD',
            'direction' => 'credit',
            'description' => 'Initial draft description',
            'posted_at' => null,
        ]);

        $this->assertNull($transaction->posted_at);
        $this->assertDatabaseHas('ledger_transactions', [
            'id' => $transaction->id,
            'description' => 'Initial draft description',
            'posted_at' => null,
        ]);

        // 2. Modify the unposted transaction
        $transaction->update([
            'description' => 'Updated draft description',
            'amount' => 1500,
        ]);

        // 3. Assert modifications succeeded
        $this->assertDatabaseHas('ledger_transactions', [
            'id' => $transaction->id,
            'description' => 'Updated draft description',
            'amount' => 1500,
        ]);
    }

    /** @test */
    public function a_posted_transaction_becomes_completely_immutable()
    {
        // 1. Create and post a transaction
        $transaction = LedgerTransaction::create([
            'type' => 'charge',
            'amount' => 2000,
            'currency' => 'USD',
            'direction' => 'credit',
            'description' => 'Finalized charge',
            'posted_at' => now(),
        ]);

        $this->assertNotNull($transaction->posted_at);

        // 2. Expect an exception or failure when trying to modify a posted transaction
        // (Depending on how you implemented your immutability guard, e.g., Model Observer throwing an exception)
        $this->expectException(\Exception::class); // Adjust to your specific exception class if custom

        $transaction->update([
            'description' => 'Attempting illegal modification',
        ]);
    }
}