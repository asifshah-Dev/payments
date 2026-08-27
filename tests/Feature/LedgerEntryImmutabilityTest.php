<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LedgerEntryImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected LedgerPostingService $postingService;
    protected Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postingService = app(LedgerPostingService::class);

        $this->merchant = Merchant::factory()->create([
            'id' => 'merchant_123',
        ]);

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
    }

    #[Test]
    public function it_prevents_updating_a_ledger_entry_on_a_posted_transaction()
    {
        $transaction = $this->postingService->post(
            type: 'payment_capture',
            amount: 10000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => 1, 'type' => 'debit', 'amount' => 10000, 'currency' => 'USD'],
                ['ledger_account_id' => 2, 'type' => 'credit', 'amount' => 10000, 'currency' => 'USD'],
            ]
        );

        $entry = $transaction->entries->first();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A ledger entry belonging to a posted transaction cannot be modified.');

        $entry->amount = 99999;
        $entry->save();
    }

    #[Test]
    public function it_prevents_deleting_a_ledger_entry_on_a_posted_transaction()
    {
        $transaction = $this->postingService->post(
            type: 'payment_capture',
            amount: 10000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => 1, 'type' => 'debit', 'amount' => 10000, 'currency' => 'USD'],
                ['ledger_account_id' => 2, 'type' => 'credit', 'amount' => 10000, 'currency' => 'USD'],
            ]
        );

        $entry = $transaction->entries->first();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A ledger entry belonging to a posted transaction cannot be deleted.');

        $entry->delete();
    }

    #[Test]
    public function it_prevents_modifying_transaction_source_after_posting()
    {
        $paymentAttempt = PaymentAttempt::factory()->create([
            'id' => 'pa_orig_123',
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
        ]);

        $otherMerchant = Merchant::factory()->create([
            'id' => 'merchant_other_999',
        ]);

        $transaction = $this->postingService->post(
            type: 'payment_capture',
            amount: 10000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => 1, 'type' => 'debit', 'amount' => 10000, 'currency' => 'USD'],
                ['ledger_account_id' => 2, 'type' => 'credit', 'amount' => 10000, 'currency' => 'USD'],
            ],
            source: $paymentAttempt
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A posted ledger transaction cannot be modified.');

        $transaction->source_id = $otherMerchant->id;
        $transaction->source_type = get_class($otherMerchant);
        $transaction->save();
    }

    #[Test]
    public function master_it_strictly_prevents_any_form_of_tampering_with_posted_ledger_entries()
    {
        $transaction = $this->postingService->post(
            type: 'payment_capture',
            amount: 10000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => 1, 'type' => 'debit', 'amount' => 10000, 'currency' => 'USD'],
                ['ledger_account_id' => 2, 'type' => 'credit', 'amount' => 10000, 'currency' => 'USD'],
            ]
        );

        $entry = $transaction->entries->first();

        // Attempt 1: Mutate amount
        try {
            $entry->amount = 999999;
            $entry->save();
            $this->fail('Expected exception when tampering with entry amount.');
        } catch (InvalidArgumentException $e) {
            $this->assertEquals('A ledger entry belonging to a posted transaction cannot be modified.', $e->getMessage());
        }

        // Attempt 2: Mutate ledger account association
        try {
            $entry->ledger_account_id = 2;
            $entry->save();
            $this->fail('Expected exception when tampering with entry account.');
        } catch (InvalidArgumentException $e) {
            $this->assertEquals('A ledger entry belonging to a posted transaction cannot be modified.', $e->getMessage());
        }

        // Attempt 3: Mutate entry type (debit vs credit)
        try {
            $entry->type = 'credit';
            $entry->save();
            $this->fail('Expected exception when tampering with entry type.');
        } catch (InvalidArgumentException $e) {
            $this->assertEquals('A ledger entry belonging to a posted transaction cannot be modified.', $e->getMessage());
        }

        // Final verification: database values remain 100% unaltered
        $freshEntry = $entry->fresh();
        $this->assertEquals(10000, $freshEntry->amount);
        $this->assertEquals(1, $freshEntry->ledger_account_id);
        $this->assertEquals('debit', $freshEntry->type);
    }
}