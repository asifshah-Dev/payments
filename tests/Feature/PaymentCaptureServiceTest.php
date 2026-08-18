<?php

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\PaymentCaptureService;
//use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_payment_capture(): void
    {
        // 1. Create a PaymentIntent with known values
        $paymentIntent = PaymentIntent::factory()->create([
            'amount' => 5000,
            'currency' => 'GBP',
            'status' => 'processing',
        ]);

        // 2. Create the PaymentAttempt linked to that PaymentIntent
        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $paymentIntent->id,
            'processor' => 'stripe',
            'status' => 'pending',
        ]);

        // 3. Get the merchant ID
        $merchantId = $paymentIntent->merchant_id;

        // 4. Create the required ledger accounts
        LedgerAccount::create([
            'name' => 'Gateway Clearing - stripe',
            'type' => 'asset',
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        LedgerAccount::create([
            'name' => 'Merchant Pending - ' . $merchantId,
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

        // 5. Capture the payment
        $service = new PaymentCaptureService();

        $transaction = $service->capture($attempt);

        // 6. Refresh the attempt from the database
        $attempt->refresh();

        // 7. Payment attempt must now be succeeded
        $this->assertEquals('succeeded', $attempt->status);

        // 8. A ledger transaction must have been created
        $this->assertNotNull($transaction);

        // 9. The transaction must belong to this payment attempt
        $this->assertEquals(
            $attempt->id,
            $transaction->payment_attempt_id
        );

        // 10. Transaction must be a payment capture
        $this->assertEquals(
            'payment_capture',
            $transaction->type
        );

        // 11. Currency must be GBP
        $this->assertEquals(
            'GBP',
            $transaction->currency
        );

        // 12. There must be exactly 3 ledger entries
        $this->assertCount(
            3,
            $transaction->entries
        );

        // 13. Calculate the expected fee
        $fee = intdiv(5000 * 2, 100);

        // 14. Calculate the merchant amount
        $merchantAmount = 5000 - $fee;

        // 15. Verify total debit
        $totalDebits = $transaction->entries
            ->where('type', 'debit')
            ->sum('amount');

        $this->assertEquals(
            5000,
            $totalDebits
        );

        // 16. Verify total credits
        $totalCredits = $transaction->entries
            ->where('type', 'credit')
            ->sum('amount');

        $this->assertEquals(
            5000,
            $totalCredits
        );

        // 17. Verify merchant received the amount after fee
        $merchantCredit = $transaction->entries
            ->where('type', 'credit')
            ->where('ledger_account_id',
                LedgerAccount::where(
                    'name',
                    'Merchant Pending - ' . $merchantId
                )->first()->id
            )
            ->first();

        $this->assertNotNull($merchantCredit);

        $this->assertEquals(
            4900,
            $merchantCredit->amount
        );

        // 18. Verify platform fee
        $feeCredit = $transaction->entries
            ->where('type', 'credit')
            ->where('ledger_account_id',
                LedgerAccount::where(
                    'name',
                    'Platform Fee Revenue - GBP'
                )->first()->id
            )
            ->first();

        $this->assertNotNull($feeCredit);

        $this->assertEquals(
            100,
            $feeCredit->amount
        );

        
    }
    public function test_already_captured_payment_cannot_be_captured_again(): void
{
    // 1. Create a PaymentIntent
    $paymentIntent = PaymentIntent::factory()->create([
        'amount' => 5000,
        'currency' => 'GBP',
        'status' => 'processing',
    ]);

    // 2. Create a pending PaymentAttempt
    $attempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'pending',
    ]);

    $merchantId = $paymentIntent->merchant_id;

    // 3. Create required ledger accounts
    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
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

    // 4. Create the service
    $service = new PaymentCaptureService();

    // 5. First capture must succeed
    $transaction = $service->capture($attempt);

    // 6. Verify the first capture created exactly one transaction
    $this->assertEquals(
        1,
        $attempt->ledgerTransactions()->count()
    );

    // 7. Verify the first transaction has exactly three entries
    $this->assertEquals(
        3,
        $transaction->entries()->count()
    );

    // 8. Refresh the attempt
    $attempt->refresh();

    $this->assertEquals(
        'succeeded',
        $attempt->status
    );

    // 9. Second capture must throw an exception
    $this->expectException(\Exception::class);

    $this->expectExceptionMessage(
        'Payment attempt has already been captured.'
    );

    $service->capture($attempt);

    // Nothing after this point executes if the exception is thrown.
}
public function test_failed_payment_cannot_be_captured(): void
{
    // 1. Create a PaymentIntent
    $paymentIntent = PaymentIntent::factory()->create([
        'amount' => 5000,
        'currency' => 'GBP',
        'status' => 'failed',
    ]);

    // 2. Create a failed PaymentAttempt
    $attempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'failed',
    ]);

    // 3. Create the service
    $service = new PaymentCaptureService();

    // 4. Capture must be rejected
    $this->expectException(\Exception::class);

    $this->expectExceptionMessage(
        'Cannot capture a payment attempt with status [failed].'
    );

    try {
        $service->capture($attempt);
    } finally {
        // 5. Verify the payment attempt was not changed
        $attempt->refresh();

        $this->assertEquals(
            'failed',
            $attempt->status
        );

        // 6. Verify no ledger transaction was created
        $this->assertEquals(
            0,
            $attempt->ledgerTransactions()->count()
        );
    }
}
public function test_cancelled_payment_cannot_be_captured(): void
{
    // 1. Create a PaymentIntent
    $paymentIntent = PaymentIntent::factory()->create([
        'amount' => 5000,
        'currency' => 'GBP',
        'status' => 'cancelled',
    ]);

    // 2. Create a cancelled PaymentAttempt
    $attempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'cancelled',
    ]);

    // 3. Create the service
    $service = new PaymentCaptureService();

    // 4. Capture must be rejected
    $this->expectException(\Exception::class);

    $this->expectExceptionMessage(
        'Cannot capture a payment attempt with status [cancelled].'
    );

    try {
        $service->capture($attempt);
    } finally {
        // 5. Status must remain cancelled
        $attempt->refresh();

        $this->assertEquals(
            'cancelled',
            $attempt->status
        );

        // 6. No ledger transaction should exist
        $this->assertEquals(
            0,
            $attempt->ledgerTransactions()->count()
        );
    }
}
public function test_processing_payment_can_be_captured(): void
{
    // 1. Create a PaymentIntent
    $paymentIntent = PaymentIntent::factory()->create([
        'amount' => 5000,
        'currency' => 'GBP',
        'status' => 'processing',
    ]);

    // 2. Create a processing PaymentAttempt
    $attempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'processing',
    ]);

    $merchantId = $paymentIntent->merchant_id;

    // 3. Create required ledger accounts
    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
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

    // 4. Capture
    $service = new PaymentCaptureService();

    $transaction = $service->capture($attempt);

    // 5. Verify attempt succeeded
    $attempt->refresh();

    $this->assertEquals(
        'succeeded',
        $attempt->status
    );

    // 6. Verify one transaction was created
    $this->assertEquals(
        1,
        $attempt->ledgerTransactions()->count()
    );

    // 7. Verify transaction has three entries
    $this->assertEquals(
        3,
        $transaction->entries()->count()
    );

    // 8. Verify ledger balances
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
public function test_capture_fails_when_gateway_clearing_account_is_missing(): void
{
    // 1. Create a PaymentIntent
    $paymentIntent = PaymentIntent::factory()->create([
        'amount' => 5000,
        'currency' => 'GBP',
        'status' => 'processing',
    ]);

    // 2. Create a pending PaymentAttempt
    $attempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'pending',
    ]);

    // 3. Create Merchant Pending account
    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $paymentIntent->merchant_id,
        'type' => 'liability',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    // 4. Create Platform Fee Revenue account
    LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'type' => 'revenue',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    // IMPORTANT:
    // We intentionally DO NOT create:
    // Gateway Clearing - stripe

    $service = new PaymentCaptureService();

    // 5. Capture must fail
    $this->expectException(\Exception::class);

    $this->expectExceptionMessage(
        'Active Gateway Clearing account not found for processor [stripe] and currency [GBP].'
    );

    try {
        $service->capture($attempt);
    } finally {
        // 6. Attempt must remain pending
        $attempt->refresh();

        $this->assertEquals(
            'pending',
            $attempt->status
        );

        // 7. No ledger transaction should exist
        $this->assertEquals(
            0,
            $attempt->ledgerTransactions()->count()
        );
    }
}
public function test_capture_fails_when_gateway_clearing_account_is_inactive(): void
{
    // 1. Create a PaymentIntent
    $paymentIntent = PaymentIntent::factory()->create([
        'amount' => 5000,
        'currency' => 'GBP',
        'status' => 'processing',
    ]);

    // 2. Create a pending PaymentAttempt
    $attempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'pending',
    ]);

    // 3. Create Gateway Clearing account, but make it INACTIVE
    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'GBP',
        'status' => 'inactive',
    ]);

    // 4. Create Merchant Pending account
    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $paymentIntent->merchant_id,
        'type' => 'liability',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    // 5. Create Platform Fee Revenue account
    LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'type' => 'revenue',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    // 6. Capture must fail
    $this->expectException(\Exception::class);

    $this->expectExceptionMessage(
        'Active Gateway Clearing account not found for processor [stripe] and currency [GBP].'
    );

    try {
        $service->capture($attempt);
    } finally {
        // 7. Attempt must remain pending
        $attempt->refresh();

        $this->assertEquals(
            'pending',
            $attempt->status
        );

        // 8. No ledger transaction should exist
        $this->assertEquals(
            0,
            $attempt->ledgerTransactions()->count()
        );
    }
}
public function test_capture_fails_when_merchant_pending_account_is_missing(): void
{
    // 1. Create PaymentIntent
    $paymentIntent = PaymentIntent::factory()->create([
        'amount' => 5000,
        'currency' => 'GBP',
        'status' => 'processing',
    ]);

    // 2. Create pending PaymentAttempt
    $attempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'pending',
    ]);

    // 3. Create Gateway Clearing account
    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    // IMPORTANT:
    // Do NOT create Merchant Pending account.

    // 4. Create Platform Fee Revenue account
    LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'type' => 'revenue',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    // 5. Capture must fail
    $this->expectException(\Exception::class);

    $this->expectExceptionMessage(
        "Active Merchant Pending account not found for merchant [{$paymentIntent->merchant_id}] and currency [GBP]."
    );

    try {
        $service->capture($attempt);
    } finally {
        // 6. Attempt must remain pending
        $attempt->refresh();

        $this->assertEquals(
            'pending',
            $attempt->status
        );

        // 7. No transaction should exist
        $this->assertEquals(
            0,
            $attempt->ledgerTransactions()->count()
        );
    }
}
public function test_capture_fails_when_merchant_pending_account_is_inactive(): void
{
    // 1. Create PaymentIntent
    $paymentIntent = PaymentIntent::factory()->create([
        'amount' => 5000,
        'currency' => 'GBP',
        'status' => 'processing',
    ]);

    // 2. Create pending PaymentAttempt
    $attempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'pending',
    ]);

    // 3. Create Gateway Clearing account
    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    // 4. Create Merchant Pending account, but make it INACTIVE
    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $paymentIntent->merchant_id,
        'type' => 'liability',
        'currency' => 'GBP',
        'status' => 'inactive',
    ]);

    // 5. Create Platform Fee Revenue account
    LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'type' => 'revenue',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    // 6. Capture must fail
    $this->expectException(\Exception::class);

    $this->expectExceptionMessage(
        "Active Merchant Pending account not found for merchant [{$paymentIntent->merchant_id}] and currency [GBP]."
    );

    try {
        $service->capture($attempt);
    } finally {
        // 7. Attempt must remain pending
        $attempt->refresh();

        $this->assertEquals(
            'pending',
            $attempt->status
        );

        // 8. No ledger transaction should exist
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

    // Intentionally DO NOT create Platform Fee Revenue account.

    $service = new PaymentCaptureService();

    $this->expectException(\Exception::class);

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

    $this->expectException(\Exception::class);

    $this->expectExceptionMessage(
        'Active Platform Fee Revenue account not found for currency [GBP].'
    );

    try {
        $service->capture($attempt);
    } finally {
        $attempt->refresh();

        $this->assertEquals('pending', $attempt->status);

        $this->assertEquals(
            0,
            $attempt->ledgerTransactions()->count()
        );
    }
}
public function test_platform_fee_is_calculated_and_applied_correctly(): void
{
    // 1. Create PaymentIntent
    $paymentIntent = PaymentIntent::factory()->create([
        'amount' => 5000,
        'currency' => 'GBP',
        'status' => 'processing',
    ]);

    // 2. Create pending PaymentAttempt
    $attempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'pending',
    ]);

    $merchantId = $paymentIntent->merchant_id;

    // 3. Create required ledger accounts
    $gateway = LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $merchantPending = LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
        'type' => 'liability',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $feeRevenue = LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'type' => 'revenue',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    // 4. Capture payment
    $service = new PaymentCaptureService();

    $transaction = $service->capture($attempt);

    // 5. Load entries
    $transaction->load('entries.account');

    $entries = $transaction->entries;

    // 6. Verify exactly 3 entries
    $this->assertCount(3, $entries);

    // 7. Verify debit
    $debit = $entries->where('type', 'debit')->first();

    $this->assertNotNull($debit);
    $this->assertEquals(5000, $debit->amount);
    $this->assertEquals('GBP', $debit->currency);
    $this->assertEquals(
        $gateway->id,
        $debit->ledger_account_id
    );

    // 8. Verify merchant credit
    $merchantCredit = $entries
        ->where('type', 'credit')
        ->where('ledger_account_id', $merchantPending->id)
        ->first();

    $this->assertNotNull($merchantCredit);
    $this->assertEquals(4900, $merchantCredit->amount);
    $this->assertEquals('GBP', $merchantCredit->currency);

    // 9. Verify platform fee credit
    $feeCredit = $entries
        ->where('type', 'credit')
        ->where('ledger_account_id', $feeRevenue->id)
        ->first();

    $this->assertNotNull($feeCredit);
    $this->assertEquals(100, $feeCredit->amount);
    $this->assertEquals('GBP', $feeCredit->currency);

    // 10. Verify accounting equation
    $totalDebits = $entries
        ->where('type', 'debit')
        ->sum('amount');

    $totalCredits = $entries
        ->where('type', 'credit')
        ->sum('amount');

    $this->assertEquals(5000, $totalDebits);
    $this->assertEquals(5000, $totalCredits);

    // 11. Verify payment succeeded
    $attempt->refresh();

    $this->assertEquals('succeeded', $attempt->status);
}
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
    ]);

    $merchantId = $paymentIntent->merchant_id;

    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
        'type' => 'liability',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Platform Fee Revenue - EUR',
        'type' => 'revenue',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    $transaction = $service->capture($attempt);

    $transaction->load('entries');

    // Transaction currency
    $this->assertEquals(
        'EUR',
        $transaction->currency
    );

    // Every ledger entry must use EUR
    $this->assertCount(3, $transaction->entries);

    foreach ($transaction->entries as $entry) {
        $this->assertEquals(
            'EUR',
            $entry->currency
        );
    }

    // Payment attempt must succeed
    $attempt->refresh();

    $this->assertEquals(
        'succeeded',
        $attempt->status
    );
}
public function test_payment_attempt_syncs_amount_and_currency_from_payment_intent(): void
{
    $paymentIntent = PaymentIntent::factory()->create([
        'amount' => 7000,
        'currency' => 'USD',
    ]);

    $attempt = PaymentAttempt::factory()->create([
        'payment_intent_id' => $paymentIntent->id,
        'amount' => 5000,
        'currency' => 'GBP',
    ]);

    $attempt->refresh();

    $this->assertEquals(
        7000,
        $attempt->amount
    );

    $this->assertEquals(
        'USD',
        $attempt->currency
    );

    $this->assertEquals(
        $paymentIntent->amount,
        $attempt->amount
    );

    $this->assertEquals(
        $paymentIntent->currency,
        $attempt->currency
    );
}
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
    ]);

    $merchantId = $paymentIntent->merchant_id;

    $gateway = LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $merchant = LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
        'type' => 'liability',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $feeRevenue = LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'type' => 'revenue',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    $transaction = $service->capture($attempt);

    $transaction->load('entries');

    $totalDebits = $transaction->entries
        ->where('type', 'debit')
        ->sum('amount');

    $totalCredits = $transaction->entries
        ->where('type', 'credit')
        ->sum('amount');

    // The fundamental double-entry accounting rule.
    $this->assertEquals(
        $totalDebits,
        $totalCredits
    );

    // The capture amount is 10,000.
    $this->assertEquals(
        10000,
        $totalDebits
    );

    $this->assertEquals(
        10000,
        $totalCredits
    );

    // There must be exactly one debit.
    $this->assertCount(
        1,
        $transaction->entries->where('type', 'debit')
    );

    // There must be exactly two credits:
    // merchant pending + platform fee revenue.
    $this->assertCount(
        2,
        $transaction->entries->where('type', 'credit')
    );

    // Verify the actual account allocation.
    $this->assertEquals(
        10000,
        $transaction->entries
            ->where('ledger_account_id', $gateway->id)
            ->first()
            ->amount
    );

    // 2% of 10,000 = 200.
    $this->assertEquals(
        200,
        $transaction->entries
            ->where('ledger_account_id', $feeRevenue->id)
            ->first()
            ->amount
    );

    // Merchant receives 10,000 - 200 = 9,800.
    $this->assertEquals(
        9800,
        $transaction->entries
            ->where('ledger_account_id', $merchant->id)
            ->first()
            ->amount
    );
}
 public function test_successful_capture_creates_only_one_ledger_transaction(): void
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
    ]);

    $merchantId = $paymentIntent->merchant_id;

    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
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

    // Perform the capture once.
    $transaction = $service->capture($attempt);

    // Query the database, not the already-loaded relationship.
    $transactions = LedgerTransaction::where(
        'payment_attempt_id',
        $attempt->id
    )->get();

    // Exactly one ledger transaction must exist.
    $this->assertCount(
        1,
        $transactions
    );

    // The returned transaction must be the same transaction
    // stored against this payment attempt.
    $this->assertEquals(
        $transaction->id,
        $transactions->first()->id
    );

    // It must specifically be a payment_capture transaction.
    $this->assertEquals(
        'payment_capture',
        $transactions->first()->type
    );

    // It must have the correct currency.
    $this->assertEquals(
        'GBP',
        $transactions->first()->currency
    );

    // It must belong to the correct payment attempt.
    $this->assertEquals(
        $attempt->id,
        $transactions->first()->payment_attempt_id
    );

    // Payment attempt must be succeeded.
    $this->assertEquals(
        'succeeded',
        $attempt->refresh()->status
    );
}
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
    ]);

    $merchantId = $paymentIntent->merchant_id;

    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
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

    $transactionsBefore = LedgerTransaction::count();
    $entriesBefore = LedgerEntry::count();

    /*
     * Temporarily disable the real fee account so that the service
     * fails before creating the ledger transaction.
     *
     * This is NOT sufficient for testing rollback of already-created
     * records, so instead we will create a database-level failure
     * after the transaction has started using a duplicate constraint.
     */

    $feeRevenue = LedgerAccount::where(
        'name',
        'Platform Fee Revenue - GBP'
    )->first();

    /*
     * Create a duplicate ledger transaction constraint situation
     * by using the same payment attempt ID if your schema has the
     * appropriate unique constraint.
     *
     * If your ledger_transactions table does NOT have such a unique
     * constraint, do not use this version.
     */

    $this->expectException(\Exception::class);

    try {
        DB::transaction(function () use ($attempt, $feeRevenue) {

            $transaction = LedgerTransaction::create([
                'payment_attempt_id' => $attempt->id,
                'type' => 'payment_capture',
                'currency' => 'GBP',
                'posted_at' => now(),
                'description' => 'Rollback test',
            ]);

            /*
             * First entry succeeds.
             */
            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $feeRevenue->id,
                'type' => 'debit',
                'amount' => 10000,
                'currency' => 'GBP',
            ]);

            /*
             * Force a real database failure.
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

        /*
         * The important assertions:
         */

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
    ]);

    $merchantId = $paymentIntent->merchant_id;

    $gateway = LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'type' => 'asset',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $merchantPending = LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
        'type' => 'liability',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $feeRevenue = LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'type' => 'revenue',
        'currency' => 'GBP',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    $transaction = $service->capture($attempt);

    $entries = $transaction->entries()->get();

    // Exactly three entries must exist.
    $this->assertCount(3, $entries);

    // Gateway Clearing: debit full payment amount.
    $debit = $entries
        ->where('ledger_account_id', $gateway->id)
        ->where('type', 'debit')
        ->first();

    $this->assertNotNull($debit);
    $this->assertEquals(10000, $debit->amount);
    $this->assertEquals('GBP', $debit->currency);

    // Merchant Pending: credit amount minus fee.
    $merchantCredit = $entries
        ->where('ledger_account_id', $merchantPending->id)
        ->where('type', 'credit')
        ->first();

    $this->assertNotNull($merchantCredit);
    $this->assertEquals(9800, $merchantCredit->amount);
    $this->assertEquals('GBP', $merchantCredit->currency);

    // Platform Fee Revenue: credit fee.
    $feeCredit = $entries
        ->where('ledger_account_id', $feeRevenue->id)
        ->where('type', 'credit')
        ->first();

    $this->assertNotNull($feeCredit);
    $this->assertEquals(200, $feeCredit->amount);
    $this->assertEquals('GBP', $feeCredit->currency);

    // No additional debit entries.
    $this->assertEquals(
        1,
        $entries->where('type', 'debit')->count()
    );

    // No additional credit entries.
    $this->assertEquals(
        2,
        $entries->where('type', 'credit')->count()
    );

    // Every entry belongs to the same transaction.
    $this->assertTrue(
        $entries->every(
            fn ($entry) => $entry->ledger_transaction_id === $transaction->id
        )
    );
}
public function test_ledger_transaction_belongs_to_correct_payment_attempt(): void
{
    $attempt = PaymentAttempt::factory()->create([
        'status' => 'pending',
    ]);

    $merchantId = $attempt->paymentIntent->merchant_id;
    $currency = $attempt->currency;
    $processor = $attempt->processor;

    LedgerAccount::create([
        'name' => "Gateway Clearing - {$processor}",
        'currency' => $currency,
        'type' => 'asset',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => "Merchant Pending - {$merchantId}",
        'currency' => $currency,
        'type' => 'liability',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => "Platform Fee Revenue - {$currency}",
        'currency' => $currency,
        'type' => 'revenue',
        'status' => 'active',
    ]);

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
public function test_successful_capture_has_exactly_one_debit_and_two_credits(): void
{
    $attempt = PaymentAttempt::factory()->create([
        'status' => 'pending',
    ]);

    $merchantId = $attempt->paymentIntent->merchant_id;
    $currency = $attempt->currency;
    $processor = $attempt->processor;

    LedgerAccount::create([
        'name' => "Gateway Clearing - {$processor}",
        'currency' => $currency,
        'type' => 'asset',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => "Merchant Pending - {$merchantId}",
        'currency' => $currency,
        'type' => 'liability',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => "Platform Fee Revenue - {$currency}",
        'currency' => $currency,
        'type' => 'revenue',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    $transaction = $service->capture($attempt);

    $entries = $transaction->entries;

    $this->assertCount(3, $entries);

    $this->assertCount(
        1,
        $entries->where('type', 'debit')
    );

    $this->assertCount(
        2,
        $entries->where('type', 'credit')
    );
}
public function test_capture_rejects_zero_amount(): void
{
    $attempt = PaymentAttempt::factory()->create([
        'status' => 'pending',
    ]);

    // Factory syncs amount from PaymentIntent,
    // so explicitly make the actual PaymentAttempt amount invalid.
    $attempt->update([
        'amount' => 0,
    ]);

    $attempt->refresh();

    $this->assertEquals(0, $attempt->amount);

    $service = new PaymentCaptureService();

    $this->expectException(Exception::class);
    $this->expectExceptionMessage(
        'Cannot capture a payment attempt with invalid amount [0].'
    );

    $service->capture($attempt);
}
public function test_capture_rejects_negative_amount(): void
{
    $attempt = PaymentAttempt::factory()->create([
        'status' => 'pending',
    ]);

    $attempt->update([
        'amount' => -500,
    ]);

    $attempt->refresh();

    $this->assertEquals(-500, $attempt->amount);

    $service = new PaymentCaptureService();

    $this->expectException(Exception::class);
    $this->expectExceptionMessage(
        'Cannot capture a payment attempt with invalid amount [-500].'
    );

    $service->capture($attempt);
}
public function test_capture_handles_minimum_positive_amount(): void
{
    $attempt = PaymentAttempt::factory()->create([
        'status' => 'pending',
    ]);

    $attempt->update([
        'amount' => 1,
        'currency' => 'GBP',
    ]);

    $attempt->refresh();

    $merchantId = $attempt->paymentIntent->merchant_id;

    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'currency' => 'GBP',
        'type' => 'asset',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
        'currency' => 'GBP',
        'type' => 'liability',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'currency' => 'GBP',
        'type' => 'revenue',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    $transaction = $service->capture($attempt);

    $entries = $transaction->entries;

    $debit = $entries
        ->where('type', 'debit')
        ->first();

    $credits = $entries
        ->where('type', 'credit');

    $feeCredit = $credits
        ->where('ledger_account_id',
            LedgerAccount::where('name', 'Platform Fee Revenue - GBP')->value('id')
        )
        ->first();

    $merchantCredit = $credits
        ->where('ledger_account_id',
            LedgerAccount::where(
                'name',
                'Merchant Pending - ' . $merchantId
            )->value('id')
        )
        ->first();

    $this->assertEquals(1, $debit->amount);
    $this->assertEquals(0, $feeCredit->amount);
    $this->assertEquals(1, $merchantCredit->amount);

    $this->assertEquals(
        $debit->amount,
        $feeCredit->amount + $merchantCredit->amount
    );
}
public function test_capture_uses_payment_attempt_amount_when_it_differs_from_payment_intent(): void
{
    $attempt = PaymentAttempt::factory()->create([
    'status' => 'pending',
    'currency' => 'GBP',
]);

$attempt->paymentIntent->update([
    'amount' => 5000,
]);

$attempt->update([
    'amount' => 7000,
]);

$attempt->refresh();
$attempt->load('paymentIntent');

$this->assertEquals(7000, $attempt->amount);
$this->assertEquals(5000, $attempt->paymentIntent->amount);

    $merchantId = $attempt->paymentIntent->merchant_id;

    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'currency' => 'GBP',
        'type' => 'asset',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
        'currency' => 'GBP',
        'type' => 'liability',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'currency' => 'GBP',
        'type' => 'revenue',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    $transaction = $service->capture($attempt);

    $debit = $transaction->entries
        ->where('type', 'debit')
        ->first();

    $feeCredit = $transaction->entries
        ->where('type', 'credit')
        ->where(
            'ledger_account_id',
            LedgerAccount::where(
                'name',
                'Platform Fee Revenue - GBP'
            )->value('id')
        )
        ->first();

    $merchantCredit = $transaction->entries
        ->where('type', 'credit')
        ->where(
            'ledger_account_id',
            LedgerAccount::where(
                'name',
                'Merchant Pending - ' . $merchantId
            )->value('id')
        )
        ->first();

    // Capture must use PaymentAttempt amount = 7000
    $this->assertEquals(7000, $debit->amount);

    // 2% fee = 140
    $this->assertEquals(140, $feeCredit->amount);

    // Merchant receives 7000 - 140 = 6860
    $this->assertEquals(6860, $merchantCredit->amount);
}
public function test_capture_uses_payment_attempt_currency_when_it_differs_from_payment_intent(): void
{
    $attempt = PaymentAttempt::factory()->create([
        'status' => 'pending',
    ]);

    $attempt->paymentIntent->update([
        'currency' => 'USD',
    ]);

    $attempt->update([
        'amount' => 5000,
        'currency' => 'GBP',
    ]);

    $attempt->refresh();
    $attempt->load('paymentIntent');

    $this->assertEquals('GBP', $attempt->currency);
    $this->assertEquals('USD', $attempt->paymentIntent->currency);

    $merchantId = $attempt->paymentIntent->merchant_id;

    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'currency' => 'GBP',
        'type' => 'asset',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
        'currency' => 'GBP',
        'type' => 'liability',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'currency' => 'GBP',
        'type' => 'revenue',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    $transaction = $service->capture($attempt);

    $this->assertEquals('GBP', $transaction->currency);

    foreach ($transaction->entries as $entry) {
        $this->assertEquals('GBP', $entry->currency);
    }
}
public function test_platform_fee_rounds_down_to_smallest_currency_unit(): void
{
   $attempt = PaymentAttempt::factory()->create([
    'status' => 'pending',
    'processor' => 'stripe',
]);

$attempt->paymentIntent->update([
    'currency' => 'GBP',
]);

$attempt->update([
    'amount' => 5001,
    'currency' => 'GBP',
]);

$attempt->refresh();

    $merchantId = $attempt->paymentIntent->merchant_id;

    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'currency' => 'GBP',
        'type' => 'asset',
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Merchant Pending - ' . $merchantId,
        'currency' => 'GBP',
        'type' => 'liability',
        'status' => 'active',
    ]);

    $feeRevenue = LedgerAccount::create([
        'name' => 'Platform Fee Revenue - GBP',
        'currency' => 'GBP',
        'type' => 'revenue',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    $transaction = $service->capture($attempt);

    $feeCredit = $transaction->entries
        ->where('type', 'credit')
        ->where('ledger_account_id', $feeRevenue->id)
        ->first();

    $merchantCredit = $transaction->entries
        ->where('type', 'credit')
        ->where(
            'ledger_account_id',
            LedgerAccount::where(
                'name',
                'Merchant Pending - ' . $merchantId
            )->value('id')
        )
        ->first();

    $debit = $transaction->entries
        ->where('type', 'debit')
        ->first();

    // 5001 × 2% = 100.02 → 100
    $this->assertEquals(100, $feeCredit->amount);

    // 5001 - 100 = 4901
    $this->assertEquals(4901, $merchantCredit->amount);

    // Original payment amount
    $this->assertEquals(5001, $debit->amount);

    // Ledger remains balanced
    $this->assertEquals(
        $transaction->entries
            ->where('type', 'debit')
            ->sum('amount'),
        $transaction->entries
            ->where('type', 'credit')
            ->sum('amount')
    );
}
public function test_capture_fails_when_payment_attempt_has_no_merchant(): void
{
    $attempt = PaymentAttempt::factory()->create([
        'status' => 'pending',
        'amount' => 5000,
        'currency' => 'GBP',
        'processor' => 'stripe',
    ]);

    // Remove merchant association from the PaymentIntent
    $attempt->paymentIntent->update([
        'merchant_id' => null,
    ]);

    // Create the gateway account so the service gets as far as
    // merchant validation.
    LedgerAccount::create([
        'name' => 'Gateway Clearing - stripe',
        'currency' => 'GBP',
        'type' => 'asset',
        'status' => 'active',
    ]);

    $service = new PaymentCaptureService();

    try {
        $service->capture($attempt);

        $this->fail(
            'Expected capture to throw an exception.'
        );
    } catch (Exception $e) {
        $this->assertEquals(
            'Payment attempt has no merchant.',
            $e->getMessage()
        );
    }

    $attempt->refresh();

    // Payment attempt must remain pending
    $this->assertEquals(
        'pending',
        $attempt->status
    );

    // No ledger transaction should have been created
    $this->assertDatabaseMissing(
        'ledger_transactions',
        [
            'payment_attempt_id' => $attempt->id,
        ]
    );
}
}
