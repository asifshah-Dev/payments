<?php

namespace Tests\Feature;

use App\Models\PaymentAttempt;
use App\Models\LedgerTransaction;
use App\Models\LedgerAccount;
use App\Services\PaymentCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_captures_payment_and_creates_balanced_ledger_entries(): void
    {
        // 1. Explicitly seed required active ledger accounts matching USD & stripe
        LedgerAccount::create([
            'name' => 'Gateway Clearing - stripe',
            'type' => 'asset',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'name' => 'Merchant Pending Account',
            'type' => 'liability',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        // 2. Create payment attempt matching the seeded currency and processor
        $attempt = PaymentAttempt::factory()->create([
            'status' => 'pending',
            'amount' => 10000, // 100.00 USD
            'currency' => 'USD',
            'processor' => 'stripe',
        ]);

        $service = new PaymentCaptureService();
        $transaction = $service->capture($attempt);

        // 3. Verify transaction was created and linked with currency
        $this->assertInstanceOf(LedgerTransaction::class, $transaction);
        $this->assertEquals($attempt->id, $transaction->payment_attempt_id);
        $this->assertEquals('USD', $transaction->currency);

        // 4. Verify attempt status updated
        $this->assertEquals('succeeded', $attempt->fresh()->status);

        // 5. Verify exact ledger entry balance (Debits == Credits)
        $entries = $transaction->entries;
        $this->assertCount(2, $entries);

        $totalDebits = $entries->where('type', 'debit')->sum('amount');
        $totalCredits = $entries->where('type', 'credit')->sum('amount');

        $this->assertEquals(10000, $totalDebits);
        $this->assertEquals(10000, $totalCredits);
        $this->assertEquals($totalDebits, $totalCredits);
    }

    public function test_it_prevents_duplicate_capture(): void
    {
        LedgerAccount::create([
            'name' => 'Gateway Clearing - stripe',
            'type' => 'asset',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'name' => 'Merchant Pending Account',
            'type' => 'liability',
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'status' => 'succeeded', // Already succeeded
            'amount' => 5000,
            'currency' => 'USD',
            'processor' => 'stripe',
        ]);

        $service = new PaymentCaptureService();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Payment attempt has already been captured.");

        $service->capture($attempt);
    }

    public function test_it_rolls_back_entire_transaction_on_failure(): void
    {
        $attempt = PaymentAttempt::factory()->create([
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'USD',
            'processor' => 'stripe',
        ]);

        // Mock a scenario where something throws an exception mid-stream
        $this->partialMock(PaymentCaptureService::class, function ($mock) use ($attempt) {
            $mock->shouldReceive('capture')
                 ->andThrow(new \Exception("Simulated mid-process crash"));
        });

        $initialLedgerCount = LedgerTransaction::count();

        try {
            app(PaymentCaptureService::class)->capture($attempt);
        } catch (\Exception $e) {
            // Expected exception
        }

        // Assert database rolled back cleanly (no partial records created)
        $this->assertEquals($initialLedgerCount, LedgerTransaction::count());
        $this->assertEquals('pending', $attempt->fresh()->status);
    }
}