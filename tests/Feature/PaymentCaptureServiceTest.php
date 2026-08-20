<?php

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\PaymentCaptureService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    private function createLedgerAccounts(
        PaymentAttempt $attempt,
        ?string $currency = null
    ): array {
        $merchantId = $attempt->paymentIntent->merchant_id;
        $currency ??= $attempt->currency;
        $processor = $attempt->processor;

        $gateway = LedgerAccount::create([
            'name' => "Gateway Clearing - {$processor}",
            'type' => 'asset',
            'currency' => $currency,
            'status' => 'active',
        ]);

        $merchantPending = LedgerAccount::create([
            'name' => "Merchant Pending - {$merchantId}",
            'type' => 'liability',
            'currency' => $currency,
            'status' => 'active',
        ]);

        $feeRevenue = LedgerAccount::create([
            'name' => "Platform Fee Revenue - {$currency}",
            'type' => 'revenue',
            'currency' => $currency,
            'status' => 'active',
        ]);

        return [
            'gateway' => $gateway,
            'merchantPending' => $merchantPending,
            'feeRevenue' => $feeRevenue,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Successful Capture
    |--------------------------------------------------------------------------
    */

    public function test_successful_payment_capture(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        $accounts = $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $attempt->refresh();

        $this->assertEquals(
            'succeeded',
            $attempt->status
        );

        $this->assertNotNull($transaction);

        $this->assertEquals(
            $attempt->id,
            $transaction->payment_attempt_id
        );

        $this->assertEquals(
            'payment_capture',
            $transaction->type
        );

        $this->assertEquals(
            'GBP',
            $transaction->currency
        );

        $this->assertCount(
            3,
            $transaction->entries
        );

        $fee = intdiv(5000 * 2, 100);
        $merchantAmount = 5000 - $fee;

        $totalDebits = $transaction->entries
            ->where('type', 'debit')
            ->sum('amount');

        $totalCredits = $transaction->entries
            ->where('type', 'credit')
            ->sum('amount');

        $this->assertEquals(
            5000,
            $totalDebits
        );

        $this->assertEquals(
            5000,
            $totalCredits
        );

        $merchantCredit = $transaction->entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['merchantPending']->id
            )
            ->first();

        $this->assertNotNull($merchantCredit);

        $this->assertEquals(
            $merchantAmount,
            $merchantCredit->amount
        );

        $feeCredit = $transaction->entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['feeRevenue']->id
            )
            ->first();

        $this->assertNotNull($feeCredit);

        $this->assertEquals(
            $fee,
            $feeCredit->amount
        );
    }

    public function test_processing_payment_can_be_captured(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'processing',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $attempt->refresh();

        $this->assertEquals(
            'succeeded',
            $attempt->status
        );

        $this->assertEquals(
            1,
            $attempt->ledgerTransactions()->count()
        );

        $this->assertEquals(
            3,
            $transaction->entries()->count()
        );

        $this->assertEquals(
            5000,
            $transaction->entries()
                ->where('type', 'debit')
                ->sum('amount')
        );

        $this->assertEquals(
            5000,
            $transaction->entries()
                ->where('type', 'credit')
                ->sum('amount')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate Capture
    |--------------------------------------------------------------------------
    */

    public function test_already_captured_payment_cannot_be_captured_again(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $this->assertEquals(
            1,
            $attempt->ledgerTransactions()->count()
        );

        $this->assertEquals(
            3,
            $transaction->entries()->count()
        );

        $attempt->refresh();

        $this->assertEquals(
            'succeeded',
            $attempt->status
        );

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Payment attempt has already been captured.'
        );

        $service->capture($attempt);
    }

    /*
    |--------------------------------------------------------------------------
    | Invalid PaymentAttempt Status
    |--------------------------------------------------------------------------
    */

    public function test_failed_payment_cannot_be_captured(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'failed',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'failed',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        $service = new PaymentCaptureService();

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Cannot capture a payment attempt with status [failed].'
        );

        try {
            $service->capture($attempt);
        } finally {
            $attempt->refresh();

            $this->assertEquals(
                'failed',
                $attempt->status
            );

            $this->assertEquals(
                0,
                $attempt->ledgerTransactions()->count()
            );
        }
    }

    public function test_cancelled_payment_cannot_be_captured(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'cancelled',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'cancelled',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        $service = new PaymentCaptureService();

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Cannot capture a payment attempt with status [cancelled].'
        );

        try {
            $service->capture($attempt);
        } finally {
            $attempt->refresh();

            $this->assertEquals(
                'cancelled',
                $attempt->status
            );

            $this->assertEquals(
                0,
                $attempt->ledgerTransactions()->count()
            );
        }
    }

    public function test_capture_rejects_invalid_payment_attempt_status(): void
    {
        $attempt = PaymentAttempt::factory()->create([
            'status' => 'expired',
            'amount' => 5000,
            'currency' => 'GBP',
            'processor' => 'stripe',
        ]);

        $service = new PaymentCaptureService();

        try {
            $service->capture($attempt);

            $this->fail(
                'Expected capture to throw an exception.'
            );
        } catch (Exception $e) {
            $this->assertEquals(
                'Invalid payment attempt status [expired] for capture.',
                $e->getMessage()
            );
        }

        $attempt->refresh();

        $this->assertEquals(
            'expired',
            $attempt->status
        );

        $this->assertDatabaseMissing(
            'ledger_transactions',
            [
                'payment_attempt_id' => $attempt->id,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Account Resolution
    |--------------------------------------------------------------------------
    */

    public function test_capture_fails_when_gateway_clearing_account_is_missing(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        LedgerAccount::create([
            'name' => 'Merchant Pending - ' . $paymentIntent->merchant_id,
            'type' => 'liability',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'name' => 'Platform Fee Revenue - GBP',
            'type' => 'revenue',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        $service = new PaymentCaptureService();

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Active Gateway Clearing account not found for processor [stripe] and currency [GBP].'
        );

        try {
            $service->capture($attempt);
        } finally {
            $attempt->refresh();

            $this->assertEquals(
                'pending',
                $attempt->status
            );

            $this->assertEquals(
                0,
                $attempt->ledgerTransactions()->count()
            );
        }
    }

    public function test_capture_fails_when_gateway_clearing_account_is_inactive(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        LedgerAccount::create([
            'name' => 'Gateway Clearing - stripe',
            'type' => 'asset',
            'currency' => 'GBP',
            'status' => 'inactive',
        ]);

        LedgerAccount::create([
            'name' => 'Merchant Pending - ' . $paymentIntent->merchant_id,
            'type' => 'liability',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'name' => 'Platform Fee Revenue - GBP',
            'type' => 'revenue',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        $service = new PaymentCaptureService();

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Active Gateway Clearing account not found for processor [stripe] and currency [GBP].'
        );

        try {
            $service->capture($attempt);
        } finally {
            $attempt->refresh();

            $this->assertEquals(
                'pending',
                $attempt->status
            );

            $this->assertEquals(
                0,
                $attempt->ledgerTransactions()->count()
            );
        }
    }

    public function test_capture_fails_when_merchant_pending_account_is_missing(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        LedgerAccount::create([
            'name' => 'Gateway Clearing - stripe',
            'type' => 'asset',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'name' => 'Platform Fee Revenue - GBP',
            'type' => 'revenue',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        $service = new PaymentCaptureService();

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            "Active Merchant Pending account not found for merchant [{$paymentIntent->merchant_id}] and currency [GBP]."
        );

        try {
            $service->capture($attempt);
        } finally {
            $attempt->refresh();

            $this->assertEquals(
                'pending',
                $attempt->status
            );

            $this->assertEquals(
                0,
                $attempt->ledgerTransactions()->count()
            );
        }
    }

    public function test_capture_fails_when_merchant_pending_account_is_inactive(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        LedgerAccount::create([
            'name' => 'Gateway Clearing - stripe',
            'type' => 'asset',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'name' => 'Merchant Pending - ' . $paymentIntent->merchant_id,
            'type' => 'liability',
            'currency' => 'GBP',
            'status' => 'inactive',
        ]);

        LedgerAccount::create([
            'name' => 'Platform Fee Revenue - GBP',
            'type' => 'revenue',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        $service = new PaymentCaptureService();

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            "Active Merchant Pending account not found for merchant [{$paymentIntent->merchant_id}] and currency [GBP]."
        );

        try {
            $service->capture($attempt);
        } finally {
            $attempt->refresh();

            $this->assertEquals(
                'pending',
                $attempt->status
            );

            $this->assertEquals(
                0,
                $attempt->ledgerTransactions()->count()
            );
        }
    }

    public function test_capture_fails_when_platform_fee_revenue_account_is_missing(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        LedgerAccount::create([
            'name' => 'Gateway Clearing - stripe',
            'type' => 'asset',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'name' => 'Merchant Pending - ' . $paymentIntent->merchant_id,
            'type' => 'liability',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        $service = new PaymentCaptureService();

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Active Platform Fee Revenue account not found for currency [GBP].'
        );

        try {
            $service->capture($attempt);
        } finally {
            $attempt->refresh();

            $this->assertEquals(
                'pending',
                $attempt->status
            );

            $this->assertEquals(
                0,
                $attempt->ledgerTransactions()->count()
            );
        }
    }

    public function test_capture_fails_when_platform_fee_revenue_account_is_inactive(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        LedgerAccount::create([
            'name' => 'Gateway Clearing - stripe',
            'type' => 'asset',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'name' => 'Merchant Pending - ' . $paymentIntent->merchant_id,
            'type' => 'liability',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'name' => 'Platform Fee Revenue - GBP',
            'type' => 'revenue',
            'currency' => 'GBP',
            'status' => 'inactive',
        ]);

        $service = new PaymentCaptureService();

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Active Platform Fee Revenue account not found for currency [GBP].'
        );

        try {
            $service->capture($attempt);
        } finally {
            $attempt->refresh();

            $this->assertEquals(
                'pending',
                $attempt->status
            );

            $this->assertEquals(
                0,
                $attempt->ledgerTransactions()->count()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fee Calculation
    |--------------------------------------------------------------------------
    */

    public function test_platform_fee_is_calculated_and_applied_correctly(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        $accounts = $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $transaction->load('entries.account');

        $entries = $transaction->entries;

        $this->assertCount(
            3,
            $entries
        );

        $debit = $entries
            ->where('type', 'debit')
            ->first();

        $this->assertNotNull($debit);

        $this->assertEquals(
            5000,
            $debit->amount
        );

        $this->assertEquals(
            'GBP',
            $debit->currency
        );

        $this->assertEquals(
            $accounts['gateway']->id,
            $debit->ledger_account_id
        );

        $merchantCredit = $entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['merchantPending']->id
            )
            ->first();

        $this->assertNotNull($merchantCredit);

        $this->assertEquals(
            4900,
            $merchantCredit->amount
        );

        $this->assertEquals(
            'GBP',
            $merchantCredit->currency
        );

        $feeCredit = $entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['feeRevenue']->id
            )
            ->first();

        $this->assertNotNull($feeCredit);

        $this->assertEquals(
            100,
            $feeCredit->amount
        );

        $this->assertEquals(
            'GBP',
            $feeCredit->currency
        );

        $totalDebits = $entries
            ->where('type', 'debit')
            ->sum('amount');

        $totalCredits = $entries
            ->where('type', 'credit')
            ->sum('amount');

        $this->assertEquals(
            5000,
            $totalDebits
        );

        $this->assertEquals(
            5000,
            $totalCredits
        );

        $attempt->refresh();

        $this->assertEquals(
            'succeeded',
            $attempt->status
        );
    }

    public function test_platform_fee_rounds_down_to_smallest_currency_unit(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5001,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5001,
            'currency' => 'GBP',
        ]);

        $accounts = $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $feeCredit = $transaction->entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['feeRevenue']->id
            )
            ->first();

        $merchantCredit = $transaction->entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['merchantPending']->id
            )
            ->first();

        $debit = $transaction->entries
            ->where('type', 'debit')
            ->first();

        // 5001 × 2% = 100.02 → 100
        $this->assertEquals(
            100,
            $feeCredit->amount
        );

        // 5001 - 100 = 4901
        $this->assertEquals(
            4901,
            $merchantCredit->amount
        );

        $this->assertEquals(
            5001,
            $debit->amount
        );

        $this->assertEquals(
            $transaction->entries
                ->where('type', 'debit')
                ->sum('amount'),
            $transaction->entries
                ->where('type', 'credit')
                ->sum('amount')
        );
    }

    public function test_capture_handles_minimum_positive_amount(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 1,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 1,
            'currency' => 'GBP',
        ]);

        $accounts = $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $debit = $transaction->entries
            ->where('type', 'debit')
            ->first();

        $feeCredit = $transaction->entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['feeRevenue']->id
            )
            ->first();

        $merchantCredit = $transaction->entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['merchantPending']->id
            )
            ->first();

        $this->assertEquals(
            1,
            $debit->amount
        );

        $this->assertEquals(
            0,
            $feeCredit->amount
        );

        $this->assertEquals(
            1,
            $merchantCredit->amount
        );

        $this->assertEquals(
            $debit->amount,
            $feeCredit->amount + $merchantCredit->amount
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PaymentAttempt Amount and Currency Are Authoritative
    |--------------------------------------------------------------------------
    */

    public function test_payment_attempt_keeps_its_own_amount_and_currency(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 7000,
            'currency' => 'GBP',
        ]);

        $attempt->refresh();
        $attempt->load('paymentIntent');

        $this->assertEquals(
            7000,
            $attempt->amount
        );

        $this->assertEquals(
            'GBP',
            $attempt->currency
        );

        $this->assertEquals(
            5000,
            $attempt->paymentIntent->amount
        );

        $this->assertEquals(
            'USD',
            $attempt->paymentIntent->currency
        );
    }

    public function test_capture_uses_payment_attempt_amount_when_it_differs_from_payment_intent(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 7000,
            'currency' => 'GBP',
        ]);

        $accounts = $this->createLedgerAccounts($attempt);

        $attempt->refresh();
        $attempt->load('paymentIntent');

        $this->assertEquals(
            7000,
            $attempt->amount
        );

        $this->assertEquals(
            5000,
            $attempt->paymentIntent->amount
        );

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $debit = $transaction->entries
            ->where('type', 'debit')
            ->first();

        $feeCredit = $transaction->entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['feeRevenue']->id
            )
            ->first();

        $merchantCredit = $transaction->entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['merchantPending']->id
            )
            ->first();

        // Capture uses PaymentAttempt amount.
        $this->assertEquals(
            7000,
            $debit->amount
        );

        // 2% of 7000 = 140.
        $this->assertEquals(
            140,
            $feeCredit->amount
        );

        // 7000 - 140 = 6860.
        $this->assertEquals(
            6860,
            $merchantCredit->amount
        );
    }

    public function test_capture_uses_payment_attempt_currency_when_it_differs_from_payment_intent(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
        ]);

        $this->createLedgerAccounts($attempt);

        $attempt->refresh();
        $attempt->load('paymentIntent');

        $this->assertEquals(
            'GBP',
            $attempt->currency
        );

        $this->assertEquals(
            'USD',
            $attempt->paymentIntent->currency
        );

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $this->assertEquals(
            'GBP',
            $transaction->currency
        );

        foreach ($transaction->entries as $entry) {
            $this->assertEquals(
                'GBP',
                $entry->currency
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Currency Consistency
    |--------------------------------------------------------------------------
    */

    public function test_capture_preserves_payment_currency_across_transaction_and_entries(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 7500,
            'currency' => 'EUR',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 7500,
            'currency' => 'EUR',
        ]);

        $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $transaction->load('entries');

        $this->assertEquals(
            'EUR',
            $transaction->currency
        );

        $this->assertCount(
            3,
            $transaction->entries
        );

        foreach ($transaction->entries as $entry) {
            $this->assertEquals(
                'EUR',
                $entry->currency
            );
        }

        $attempt->refresh();

        $this->assertEquals(
            'succeeded',
            $attempt->status
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Ledger Structure
    |--------------------------------------------------------------------------
    */

    public function test_successful_capture_has_balanced_ledger_entries(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 10000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 10000,
            'currency' => 'GBP',
        ]);

        $accounts = $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $transaction->load('entries');

        $totalDebits = $transaction->entries
            ->where('type', 'debit')
            ->sum('amount');

        $totalCredits = $transaction->entries
            ->where('type', 'credit')
            ->sum('amount');

        $this->assertEquals(
            $totalDebits,
            $totalCredits
        );

        $this->assertEquals(
            10000,
            $totalDebits
        );

        $this->assertEquals(
            10000,
            $totalCredits
        );

        $this->assertCount(
            1,
            $transaction->entries->where('type', 'debit')
        );

        $this->assertCount(
            2,
            $transaction->entries->where('type', 'credit')
        );

        $this->assertEquals(
            10000,
            $transaction->entries
                ->where('ledger_account_id', $accounts['gateway']->id)
                ->first()
                ->amount
        );

        // 2% of 10,000 = 200.
        $this->assertEquals(
            200,
            $transaction->entries
                ->where('ledger_account_id', $accounts['feeRevenue']->id)
                ->first()
                ->amount
        );

        // 10,000 - 200 = 9,800.
        $this->assertEquals(
            9800,
            $transaction->entries
                ->where('ledger_account_id', $accounts['merchantPending']->id)
                ->first()
                ->amount
        );
    }

    public function test_successful_capture_creates_correct_ledger_entry_structure(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 10000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 10000,
            'currency' => 'GBP',
        ]);

        $accounts = $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $entries = $transaction->entries()->get();

        $this->assertCount(
            3,
            $entries
        );

        $debit = $entries
            ->where('ledger_account_id', $accounts['gateway']->id)
            ->where('type', 'debit')
            ->first();

        $this->assertNotNull($debit);

        $this->assertEquals(
            10000,
            $debit->amount
        );

        $this->assertEquals(
            'GBP',
            $debit->currency
        );

        $merchantCredit = $entries
            ->where('ledger_account_id', $accounts['merchantPending']->id)
            ->where('type', 'credit')
            ->first();

        $this->assertNotNull($merchantCredit);

        $this->assertEquals(
            9800,
            $merchantCredit->amount
        );

        $this->assertEquals(
            'GBP',
            $merchantCredit->currency
        );

        $feeCredit = $entries
            ->where('ledger_account_id', $accounts['feeRevenue']->id)
            ->where('type', 'credit')
            ->first();

        $this->assertNotNull($feeCredit);

        $this->assertEquals(
            200,
            $feeCredit->amount
        );

        $this->assertEquals(
            'GBP',
            $feeCredit->currency
        );

        $this->assertEquals(
            1,
            $entries->where('type', 'debit')->count()
        );

        $this->assertEquals(
            2,
            $entries->where('type', 'credit')->count()
        );

        $this->assertTrue(
            $entries->every(
                fn ($entry) =>
                    $entry->ledger_transaction_id === $transaction->id
            )
        );
    }

    public function test_successful_capture_has_exactly_one_debit_and_two_credits(): void
    {
        $attempt = PaymentAttempt::factory()->create([
            'status' => 'pending',
            'amount' => 10000,
            'currency' => 'GBP',
            'processor' => 'stripe',
        ]);

        $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $entries = $transaction->entries;

        $this->assertCount(
            3,
            $entries
        );

        $this->assertCount(
            1,
            $entries->where('type', 'debit')
        );

        $this->assertCount(
            2,
            $entries->where('type', 'credit')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Ledger Transaction Ownership
    |--------------------------------------------------------------------------
    */

    public function test_ledger_transaction_belongs_to_correct_payment_attempt(): void
    {
        $attempt = PaymentAttempt::factory()->create([
            'status' => 'pending',
        ]);

        $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $this->assertEquals(
            $attempt->id,
            $transaction->payment_attempt_id
        );

        $this->assertTrue(
            $attempt->ledgerTransactions->contains($transaction)
        );
    }

    public function test_successful_capture_creates_only_one_ledger_transaction(): void
    {
        $attempt = PaymentAttempt::factory()->create([
            'amount' => 10000,
            'currency' => 'GBP',
            'processor' => 'stripe',
            'status' => 'pending',
        ]);

        $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $transactions = LedgerTransaction::where(
            'payment_attempt_id',
            $attempt->id
        )->get();

        $this->assertCount(
            1,
            $transactions
        );

        $this->assertEquals(
            $transaction->id,
            $transactions->first()->id
        );

        $this->assertEquals(
            'payment_capture',
            $transactions->first()->type
        );

        $this->assertEquals(
            'GBP',
            $transactions->first()->currency
        );

        $this->assertEquals(
            $attempt->id,
            $transactions->first()->payment_attempt_id
        );

        $this->assertEquals(
            'succeeded',
            $attempt->refresh()->status
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Invalid Amount
    |--------------------------------------------------------------------------
    */

    public function test_capture_rejects_zero_amount(): void
    {
        $attempt = PaymentAttempt::factory()->create([
            'status' => 'pending',
            'amount' => 0,
            'currency' => 'GBP',
            'processor' => 'stripe',
        ]);

        $attempt->refresh();

        $this->assertEquals(
            0,
            $attempt->amount
        );

        $service = new PaymentCaptureService();

        $this->expectException(Exception::class);

        $this->expectExceptionMessage(
            'Cannot capture a payment attempt with invalid amount [0].'
        );

        try {
            $service->capture($attempt);
        } finally {
            $attempt->refresh();

            $this->assertEquals(
                'pending',
                $attempt->status
            );

            $this->assertEquals(
                0,
                $attempt->ledgerTransactions()->count()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Capture Uses Attempt Values
    |--------------------------------------------------------------------------
    */

    public function test_capture_uses_payment_attempt_values_as_authoritative_values(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'USD',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 7500,
            'currency' => 'GBP',
        ]);

        $accounts = $this->createLedgerAccounts($attempt);

        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        $debit = $transaction->entries
            ->where('type', 'debit')
            ->where(
                'ledger_account_id',
                $accounts['gateway']->id
            )
            ->first();

        $feeCredit = $transaction->entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['feeRevenue']->id
            )
            ->first();

        $merchantCredit = $transaction->entries
            ->where('type', 'credit')
            ->where(
                'ledger_account_id',
                $accounts['merchantPending']->id
            )
            ->first();

        // PaymentIntent = 5000 USD.
        // PaymentAttempt = 7500 GBP.
        // Capture must use the PaymentAttempt.

        $this->assertEquals(
            7500,
            $debit->amount
        );

        $this->assertEquals(
            'GBP',
            $debit->currency
        );

        // 2% of 7500 = 150.
        $this->assertEquals(
            150,
            $feeCredit->amount
        );

        $this->assertEquals(
            7350,
            $merchantCredit->amount
        );

        $this->assertEquals(
            'GBP',
            $transaction->currency
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Rollback
    |--------------------------------------------------------------------------
    */

    public function test_capture_rolls_back_when_ledger_entry_creation_fails(): void
    {
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 10000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
            'amount' => 10000,
            'currency' => 'GBP',
        ]);

        $accounts = $this->createLedgerAccounts($attempt);

        $transactionsBefore = LedgerTransaction::count();
        $entriesBefore = LedgerEntry::count();

        $this->expectException(Exception::class);

        try {
            DB::transaction(function () use ($attempt, $accounts) {
                $transaction = LedgerTransaction::create([
                    'payment_attempt_id' => $attempt->id,
                    'type' => 'payment_capture',
                    'currency' => 'GBP',
                    'posted_at' => now(),
                    'description' => 'Rollback test',
                ]);

                LedgerEntry::create([
                    'ledger_transaction_id' => $transaction->id,
                    'ledger_account_id' => $accounts['gateway']->id,
                    'type' => 'debit',
                    'amount' => 10000,
                    'currency' => 'GBP',
                ]);

                /*
                 * Deliberately use a non-existent ledger account ID.
                 * The foreign key must reject this insert.
                 */
                LedgerEntry::create([
                    'ledger_transaction_id' => $transaction->id,
                    'ledger_account_id' => '00000000-0000-0000-0000-000000000000',
                    'type' => 'credit',
                    'amount' => 10000,
                    'currency' => 'GBP',
                ]);
            });
        } finally {
            $attempt->refresh();

            $this->assertEquals(
                'pending',
                $attempt->status
            );

            $this->assertEquals(
                $transactionsBefore,
                LedgerTransaction::count()
            );

            $this->assertEquals(
                $entriesBefore,
                LedgerEntry::count()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Account Resolution Must Happen Before Ledger Creation
    |--------------------------------------------------------------------------
    */

    public function test_capture_does_not_create_ledger_transaction_when_account_resolution_fails(): void
    {
        $attempt = PaymentAttempt::factory()->create([
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'GBP',
            'processor' => 'stripe',
        ]);

        /*
         * Deliberately do NOT create the Gateway Clearing account.
         */

        $service = new PaymentCaptureService();

        try {
            $service->capture($attempt);

            $this->fail(
                'Expected capture to throw an exception.'
            );
        } catch (Exception $e) {
            $this->assertEquals(
                'Active Gateway Clearing account not found for processor [stripe] and currency [GBP].',
                $e->getMessage()
            );
        }

        $attempt->refresh();

        $this->assertEquals(
            'pending',
            $attempt->status
        );

        $this->assertDatabaseMissing(
            'ledger_transactions',
            [
                'payment_attempt_id' => $attempt->id,
            ]
        );

        $this->assertDatabaseCount(
            'ledger_entries',
            0
        );
    }
}