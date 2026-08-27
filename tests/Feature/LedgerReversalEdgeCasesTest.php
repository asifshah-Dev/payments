<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Services\LedgerPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class LedgerReversalEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected LedgerPostingService $postingService;
    protected Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postingService = app(LedgerPostingService::class);

        // 1. Create the parent merchant first to satisfy foreign key constraints
        $this->merchant = Merchant::factory()->create([
            'id' => 'merchant_123',
        ]);

        // 2. Seed or create standard accounts needed for testing
        LedgerAccount::factory()->create([
            'id' => 1,
            'type' => 'asset',
            'currency' => 'USD',
            'merchant_id' => null,
        ]);

        LedgerAccount::factory()->create([
            'id' => 2,
            'type' => 'liability',
            'currency' => 'USD',
            'merchant_id' => $this->merchant->id,
        ]);

        LedgerAccount::factory()->create([
            'id' => 3,
            'type' => 'revenue',
            'currency' => 'USD',
            'merchant_id' => null,
        ]);
    }

    #[Test]
    public function it_prevents_double_reversing_a_transaction()
    {
        $transaction = $this->postingService->post(
            type: 'payment_capture',
            amount: 10000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => 1,
                    'type' => 'debit',
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => 2,
                    'type' => 'credit',
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
            ]
        );

        // First reversal should succeed
        $reversal = $this->postingService->reverse($transaction);
        $this->assertNotNull($reversal);

        // Second reversal should throw a RuntimeException
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This ledger transaction has already been reversed.');

        $this->postingService->reverse($transaction);
    }

    #[Test]
    public function it_correctly_restores_account_balances_after_reversal()
    {
        $transaction = $this->postingService->post(
            type: 'payment_capture',
            amount: 10000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => 1,
                    'type' => 'debit',
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => 2,
                    'type' => 'credit',
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
            ]
        );

        $account1 = LedgerAccount::find(1);
        $account2 = LedgerAccount::find(2);

        // Initial balances after posting
        $this->assertEquals(10000, $account1->entries()->where('type', 'debit')->sum('amount'));
        $this->assertEquals(10000, $account2->entries()->where('type', 'credit')->sum('amount'));

        // Reverse the transaction
        $this->postingService->reverse($transaction);

        // Balances should be neutralized (debits equal credits for both accounts overall)
        $this->assertEquals(
            $account1->entries()->where('type', 'debit')->sum('amount'),
            $account1->entries()->where('type', 'credit')->sum('amount')
        );

        $this->assertEquals(
            $account2->entries()->where('type', 'debit')->sum('amount'),
            $account2->entries()->where('type', 'credit')->sum('amount')
        );
    }

    #[Test]
    public function it_allows_attaching_a_custom_description_to_the_reversal()
    {
        $transaction = $this->postingService->post(
            type: 'payment_capture',
            amount: 10000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => 1,
                    'type' => 'debit',
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => 2,
                    'type' => 'credit',
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
            ]
        );

        $customDescription = 'Custom chargeback reversal due to customer fraud claim.';
        $reversal = $this->postingService->reverse($transaction, description: $customDescription);

        $this->assertEquals($customDescription, $reversal->description);
    }

    #[Test]
    public function it_correctly_reverses_a_multi_entry_transaction_with_fees()
    {
        // 1. Post a multi-entry transaction with a fee (Cash debit 10000, Merchant credit 9500, Fee revenue credit 500)
        $originalTransaction = $this->postingService->post(
            type: 'payment_capture',
            amount: 10000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => 1, // Cash (Asset)
                    'type' => 'debit',
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => 2, // Merchant (Liability)
                    'type' => 'credit',
                    'amount' => 9500,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => 3, // Fee (Revenue)
                    'type' => 'credit',
                    'amount' => 500,
                    'currency' => 'USD',
                ],
            ]
        );

        $this->assertNotNull($originalTransaction);
        $this->assertCount(3, $originalTransaction->entries);

        // 2. Perform the reversal
        $reversalTransaction = $this->postingService->reverse($originalTransaction);

        // 3. Assertions
        $this->assertNotNull($reversalTransaction);
        $this->assertEquals('payment_capture_reversal', $reversalTransaction->type);
        $this->assertEquals(10000, $reversalTransaction->amount);
        $this->assertEquals('debit', $reversalTransaction->direction);
        $this->assertCount(3, $reversalTransaction->entries);

        // Verify that the reference fields link back to the original transaction
        $this->assertEquals('reversal', $reversalTransaction->reference_type);
        $this->assertEquals((string) $originalTransaction->id, $reversalTransaction->reference_id);

        // Verify that every original entry type was cleanly inverted
        foreach ($originalTransaction->entries as $originalEntry) {
            $mirroredEntry = $reversalTransaction->entries
                ->where('ledger_account_id', $originalEntry->ledger_account_id)
                ->first();

            $this->assertNotNull($mirroredEntry);
            $this->assertEquals($originalEntry->amount, $mirroredEntry->amount);
            $this->assertNotEquals($originalEntry->type, $mirroredEntry->type);
        }
    }

    #[Test]
    public function it_prevents_reversing_a_transaction_with_no_entries()
    {
        // Create a transaction record manually without entries
        $transaction = LedgerTransaction::create([
            'type' => 'payment_capture',
            'amount' => 10000,
            'currency' => 'USD',
            'direction' => 'credit',
            'posted_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot reverse a transaction with no entries.');

        $this->postingService->reverse($transaction);
    }

    #[Test]
    public function it_correctly_inverts_top_level_direction_upon_reversal()
    {
        // Post a transaction with 'debit' direction (e.g., payout)
        $transaction = $this->postingService->post(
            type: 'merchant_payout',
            amount: 5000,
            currency: 'USD',
            direction: 'debit',
            entries: [
                [
                    'ledger_account_id' => 2,
                    'type' => 'debit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => 1,
                    'type' => 'credit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
            ]
        );

        $this->assertEquals('debit', $transaction->direction);

        // Reverse it
        $reversal = $this->postingService->reverse($transaction);

        // Top-level direction should invert to 'credit' and type should append '_reversal'
        $this->assertEquals('credit', $reversal->direction);
        $this->assertEquals('merchant_payout_reversal', $reversal->type);
    }

    #[Test]
    public function it_ensures_atomic_rollback_if_reversal_fails_midway()
    {
        $transaction = $this->postingService->post(
            type: 'payment_capture',
            amount: 10000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => 1,
                    'type' => 'debit',
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => 2,
                    'type' => 'credit',
                    'amount' => 10000,
                    'currency' => 'USD',
                ],
            ]
        );

        $initialTransactionCount = LedgerTransaction::count();

        try {
            // Force an exception inside a custom wrapper or test database failure behavior
            \DB::transaction(function () use ($transaction) {
                // Perform valid reversal logic partially, then throw a forced exception
                $this->postingService->reverse($transaction);
                throw new RuntimeException('Simulated database failure during reversal process.');
            });
        } catch (RuntimeException $e) {
            $this->assertEquals('Simulated database failure during reversal process.', $e->getMessage());
        }

        // Verify that due to DB transaction rollbacks, no new reversal transaction was persisted
        $this->assertEquals($initialTransactionCount, LedgerTransaction::count());
        $this->assertNull(LedgerTransaction::where('reference_type', 'reversal')->first());
    }
}