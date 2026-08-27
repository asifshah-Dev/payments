<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LedgerAuditabilityTest extends TestCase
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
    public function it_links_ledger_transaction_to_its_originating_source_event()
    {
        // 1. Simulate the upstream business event
        $paymentAttempt = PaymentAttempt::factory()->create([
            'id' => 'pa_987654',
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
        ]);

        // 2. Post the transaction, tying it to the source model
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

        // 3. Assertions verifying the audit lineage
        $this->assertNotNull($transaction->source);
        $this->assertEquals(get_class($paymentAttempt), $transaction->source_type);
        $this->assertEquals($paymentAttempt->id, $transaction->source_id);
        $this->assertTrue($transaction->source->is($paymentAttempt));
    }

    #[Test]
    public function it_allows_posting_a_transaction_without_an_upstream_source()
    {
        // Post a transaction without passing a source (e.g., a manual admin adjustment)
        $transaction = $this->postingService->post(
            type: 'manual_adjustment',
            amount: 5000,
            currency: 'USD',
            direction: 'debit',
            entries: [
                ['ledger_account_id' => 2, 'type' => 'debit', 'amount' => 5000, 'currency' => 'USD'],
                ['ledger_account_id' => 1, 'type' => 'credit', 'amount' => 5000, 'currency' => 'USD'],
            ],
            source: null,
            description: 'Manual ledger correction by support admin.'
        );

        // Assertions verifying source fields are safely null
        $this->assertNull($transaction->source);
        $this->assertNull($transaction->source_type);
        $this->assertNull($transaction->source_id);
        $this->assertEquals('Manual ledger correction by support admin.', $transaction->description);
    }

    #[Test]
    public function it_allows_retrieving_ledger_transactions_from_the_source_model()
    {
        $paymentAttempt = PaymentAttempt::factory()->create([
            'id' => 'pa_source_123',
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
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

        // Query transactions linked to this source using morph query scoping
        $linkedTransactions = LedgerTransaction::whereMorphedTo('source', $paymentAttempt)->get();

        $this->assertCount(1, $linkedTransactions);
        $this->assertTrue($linkedTransactions->first()->is($transaction));
    }

    #[Test]
    public function it_preserves_audit_source_on_transaction_reversal()
    {
        $paymentAttempt = PaymentAttempt::factory()->create([
            'id' => 'pa_rev_source_999',
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
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

        $reversal = $this->postingService->reverse($transaction);

        // Verify the reversal inherited the original audit source
        $this->assertNotNull($reversal->source);
        $this->assertTrue($reversal->source->is($paymentAttempt));
        $this->assertEquals(get_class($paymentAttempt), $reversal->source_type);
        $this->assertEquals($paymentAttempt->id, $reversal->source_id);
    }

    #[Test]
    public function it_supports_multiple_distinct_source_model_types()
    {
        $paymentAttempt = PaymentAttempt::factory()->create([
            'id' => 'pa_multi_1',
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
        ]);

        $merchantSource = Merchant::factory()->create([
            'id' => 'merchant_source_999',
        ]);

        $tx1 = $this->postingService->post(
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

        $tx2 = $this->postingService->post(
            type: 'merchant_adjustment',
            amount: 2500,
            currency: 'USD',
            direction: 'debit',
            entries: [
                ['ledger_account_id' => 2, 'type' => 'debit', 'amount' => 2500, 'currency' => 'USD'],
                ['ledger_account_id' => 1, 'type' => 'credit', 'amount' => 2500, 'currency' => 'USD'],
            ],
            source: $merchantSource
        );

        $this->assertTrue($tx1->source->is($paymentAttempt));
        $this->assertEquals(PaymentAttempt::class, $tx1->source_type);

        $this->assertTrue($tx2->source->is($merchantSource));
        $this->assertEquals(Merchant::class, $tx2->source_type);
    }

    #[Test]
    public function it_can_eager_load_the_audit_source_alongside_ledger_entries()
    {
        $paymentAttempt = PaymentAttempt::factory()->create([
            'id' => 'pa_eager_777',
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
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

        // Fetch fresh from DB with eager loading
        $fetchedTransaction = LedgerTransaction::with(['source', 'entries'])->find($transaction->id);

        $this->assertTrue($fetchedTransaction->relationLoaded('source'));
        $this->assertTrue($fetchedTransaction->relationLoaded('entries'));
        $this->assertTrue($fetchedTransaction->source->is($paymentAttempt));
        $this->assertCount(2, $fetchedTransaction->entries);
    }

    #[Test]
    public function it_allows_multiple_ledger_transactions_to_originate_from_the_same_source_event()
    {
        $paymentAttempt = PaymentAttempt::factory()->create([
            'id' => 'pa_shared_555',
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
        ]);

        // Transaction 1: Main payment capture
        $tx1 = $this->postingService->post(
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

        // Transaction 2: Fee deduction linked to the exact same payment attempt
        $tx2 = $this->postingService->post(
            type: 'processor_fee',
            amount: 200,
            currency: 'USD',
            direction: 'debit',
            entries: [
                ['ledger_account_id' => 2, 'type' => 'debit', 'amount' => 200, 'currency' => 'USD'],
                ['ledger_account_id' => 1, 'type' => 'credit', 'amount' => 200, 'currency' => 'USD'],
            ],
            source: $paymentAttempt
        );

        // Query all transactions tied to this single source
        $linkedTransactions = LedgerTransaction::whereMorphedTo('source', $paymentAttempt)->get();

        $this->assertCount(2, $linkedTransactions);
        $this->assertTrue($linkedTransactions->contains($tx1));
        $this->assertTrue($linkedTransactions->contains($tx2));
    }

    #[Test]
    public function it_can_query_unattributed_transactions_for_compliance_auditing()
    {
        $paymentAttempt = PaymentAttempt::factory()->create([
            'id' => 'pa_audit_111',
            'amount' => 10000,
            'currency' => 'USD',
            'status' => 'succeeded',
        ]);

        // Attributed transaction
        $this->postingService->post(
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

        // Unattributed (manual) transaction
        $manualTx = $this->postingService->post(
            type: 'manual_adjustment',
            amount: 5000,
            currency: 'USD',
            direction: 'debit',
            entries: [
                ['ledger_account_id' => 2, 'type' => 'debit', 'amount' => 5000, 'currency' => 'USD'],
                ['ledger_account_id' => 1, 'type' => 'credit', 'amount' => 5000, 'currency' => 'USD'],
            ],
            source: null,
            description: 'Direct administrative balance correction'
        );

        // Query transactions where source_type is null
        $unattributedTransactions = LedgerTransaction::whereNull('source_type')->get();

        $this->assertCount(1, $unattributedTransactions);
        $this->assertTrue($unattributedTransactions->first()->is($manualTx));
    }

    #[Test]
    public function master_it_generates_complete_audit_history_reconstructing_balance_changes()
    {
        // 1. Simulate an incoming customer payment attempt
        $paymentAttempt = PaymentAttempt::factory()->create([
            'id' => 'pa_master_999',
            'amount' => 15000,
            'currency' => 'USD',
            'status' => 'succeeded',
        ]);

        // Post payment capture (increases merchant balance)
        $this->postingService->post(
            type: 'payment_capture',
            amount: 15000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                ['ledger_account_id' => 1, 'type' => 'debit', 'amount' => 15000, 'currency' => 'USD'],
                ['ledger_account_id' => 2, 'type' => 'credit', 'amount' => 15000, 'currency' => 'USD'],
            ],
            source: $paymentAttempt,
            description: 'Customer payment captured successfully'
        );

        // 2. Simulate a merchant-requested payout (decreases merchant balance)
        $this->postingService->post(
            type: 'payout',
            amount: 5000,
            currency: 'USD',
            direction: 'debit',
            entries: [
                ['ledger_account_id' => 2, 'type' => 'debit', 'amount' => 5000, 'currency' => 'USD'],
                ['ledger_account_id' => 1, 'type' => 'credit', 'amount' => 5000, 'currency' => 'USD'],
            ],
            source: $this->merchant,
            description: 'Merchant requested balance payout'
        );

        // 3. Reconstruct full audit history for the merchant's liability account (Account #2)
        $auditTrail = LedgerTransaction::with('source')
            ->whereHas('entries', function ($query) {
                $query->where('ledger_account_id', 2);
            })
            ->orderBy('created_at', 'asc')
            ->get();

        // 4. Verify the system can fully answer "What happened to the balance and why?"
        $this->assertCount(2, $auditTrail);

        // First event: Payment Capture linked to PaymentAttempt
        $this->assertEquals('payment_capture', $auditTrail[0]->type);
        $this->assertEquals(PaymentAttempt::class, $auditTrail[0]->source_type);
        $this->assertEquals('pa_master_999', $auditTrail[0]->source_id);
        $this->assertInstanceOf(PaymentAttempt::class, $auditTrail[0]->source);

        // Second event: Payout linked to Merchant
        $this->assertEquals('payout', $auditTrail[1]->type);
        $this->assertEquals(Merchant::class, $auditTrail[1]->source_type);
        $this->assertEquals($this->merchant->id, $auditTrail[1]->source_id);
        $this->assertInstanceOf(Merchant::class, $auditTrail[1]->source);
    }
}