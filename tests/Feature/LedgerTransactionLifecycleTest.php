<?php

namespace Tests\Feature;

use App\Models\LedgerTransaction;
use App\Models\LedgerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\DB; 

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
    /** @test */
    public function an_unbalanced_ledger_transaction_cannot_be_posted()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        // Explicitly set a valid account type
        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'asset', // <--- Add this
        ]);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'PKR',
        ]);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 500, // Unbalanced
            'type' => 'credit',
            'currency' => 'PKR',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $transaction->post();

        $this->assertNull($transaction->fresh()->posted_at);
    }
    /** @test */
    public function a_balanced_ledger_transaction_can_be_posted()
    {
        // 1. Create an unposted transaction
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        // Explicitly set a valid account type (e.g., 'asset')
        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'asset', 
        ]);

        // 2. Add balanced entries (Debit: 1,000, Credit: 1,000)
        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'PKR',
        ]);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'credit',
            'currency' => 'PKR',
        ]);

        $this->assertNull($transaction->posted_at);

        // 3. Post the transaction
        $transaction->post();

        // 4. Assertions
        $transaction->refresh();
        $this->assertNotNull($transaction->posted_at);
        $this->assertCount(2, $transaction->entries);

        // 5. Assert the transaction is now immutable
        $this->expectException(\Exception::class);
        
        $transaction->update([
            'description' => 'Attempting to modify a posted transaction',
        ]);
    }
    /** @test */
    public function a_transaction_with_debit_only_cannot_be_posted()
    {
        // 1. Create an unposted transaction
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'asset',
        ]);

        // 2. Add ONLY a debit entry (no credit entries)
        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'PKR',
        ]);

        // 3. Expect posting to fail
        $this->expectException(\InvalidArgumentException::class);

        // 4. Attempt to post
        $transaction->post();

        // 5. Verify posted_at remains NULL
        $this->assertNull($transaction->fresh()->posted_at);
    }
    /** @test */
    public function a_transaction_with_credit_only_cannot_be_posted()
    {
        // 1. Create an unposted transaction
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'liability',
        ]);

        // 2. Add ONLY a credit entry (no debit entries)
        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'credit',
            'currency' => 'PKR',
        ]);

        // 3. Expect posting to fail
        $this->expectException(\InvalidArgumentException::class);

        // 4. Attempt to post
        $transaction->post();

        // 5. Verify posted_at remains NULL
        $this->assertNull($transaction->fresh()->posted_at);
    }

    /** @test */
    public function a_transaction_with_no_entries_cannot_be_posted()
    {
        // 1. Create an unposted transaction with zero entries
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        // 2. Expect posting to fail because total entries count is 0
        $this->expectException(\InvalidArgumentException::class);

        // 3. Attempt to post
        $transaction->post();

        // 4. Verify posted_at remains NULL
        $this->assertNull($transaction->fresh()->posted_at);
    }
    /** @test */
    public function posting_failure_ensures_transaction_remains_unposted_atomically()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'asset',
        ]);

        // Balanced entries
        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'PKR',
        ]);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'credit',
            'currency' => 'PKR',
        ]);

        $this->assertNull($transaction->posted_at);

        // Simulate a mid-posting failure by forcing an exception inside a custom transaction or mock,
        // or test that if a runtime error happens during post, posted_at is never persisted.
        try {
            DB::transaction(function () use ($transaction) {
                // Perform the normal post action logic
                $transaction->update(['posted_at' => now()]);

                // Force an unexpected runtime failure right after updating
                throw new \RuntimeException("Simulated database or service failure during post");
            });
        } catch (\RuntimeException $e) {
            // Expected exception caught
        }

        // Verify that atomicity rolled back the change and posted_at is still NULL
        $this->assertNull($transaction->fresh()->posted_at);
    }
    /** @test */
    public function a_posted_transaction_cannot_receive_new_ledger_entries()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'asset',
        ]);

        // Add initial balanced entries
        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'PKR',
        ]);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'credit',
            'currency' => 'PKR',
        ]);

        // Post the transaction
        $transaction->post();

        $initialCount = $transaction->entries()->count();

        // Expect exception when trying to create a new entry on a posted transaction
        $this->expectException(\InvalidArgumentException::class);

        try {
            $transaction->entries()->create([
                'ledger_account_id' => $account->id,
                'amount' => 500,
                'type' => 'debit',
                'currency' => 'PKR',
            ]);
        } finally {
            // Verify entry count remains unchanged
            $this->assertEquals($initialCount, $transaction->fresh()->entries()->count());
        }
    }
    /** @test */
    public function a_posted_transaction_cannot_be_deleted()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'asset',
        ]);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'PKR',
        ]);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'credit',
            'currency' => 'PKR',
        ]);

        // Post the transaction
        $transaction->post();

        // Expect deletion to fail
        $this->expectException(\InvalidArgumentException::class);

        try {
            $transaction->delete();
        } finally {
            // Verify the transaction still exists in the database
            $this->assertNotNull($transaction->fresh());
        }
    }
    /** @test */
    public function a_posted_transaction_cannot_be_modified()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'asset',
        ]);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'PKR',
        ]);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'credit',
            'currency' => 'PKR',
        ]);

        // Post the transaction
        $transaction->post();

        // Expect update/modification to fail
        $this->expectException(\InvalidArgumentException::class);

        try {
            $transaction->update(['amount' => 5000]);
        } finally {
            // Verify the amount remains unchanged in the database
            $this->assertEquals(1000, $transaction->fresh()->amount);
        }
    }
}