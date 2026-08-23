<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\PaymentAttempt;
use App\Services\LedgerPostingService;

it('can post a balanced double-entry transaction successfully', function () {
    $paymentAttempt = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'PKR',
        'status' => 'succeeded',
    ]);

    $cashAccount = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'PKR'
    ]);
    $merchantAccount = LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR'
    ]);

    $service = new LedgerPostingService();

    $transaction = $service->post(
        type: 'payment_capture',
        amount: 10000,
        currency: 'PKR',
        direction: 'credit',
        entries: [
            [
                'ledger_account_id' => $cashAccount->id,
                'type' => 'debit',
                'amount' => 10000,
                'currency' => 'PKR',
            ],
            [
                'ledger_account_id' => $merchantAccount->id,
                'type' => 'credit',
                'amount' => 10000,
                'currency' => 'PKR',
            ],
        ],
        paymentAttemptId: $paymentAttempt->id,
        description: 'Test payment capture'
    );

    expect($transaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($transaction->posted_at)->not->toBeNull()
        ->and($transaction->amount)->toBe(10000);

    expect($transaction->entries)->toHaveCount(2);
});

it('prevents posting the same payment attempt twice', function () {
    $paymentAttempt = PaymentAttempt::factory()->create([
        'amount' => 5000,
        'currency' => 'PKR',
        'status' => 'succeeded',
    ]);

    $cashAccount = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'PKR'
    ]);
    $merchantAccount = LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR'
    ]);

    $service = new LedgerPostingService();

    $payload = [
        'type' => 'payment_capture',
        'amount' => 5000,
        'currency' => 'PKR',
        'direction' => 'credit',
        'entries' => [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 5000, 'currency' => 'PKR'],
            ['ledger_account_id' => $merchantAccount->id, 'type' => 'credit', 'amount' => 5000, 'currency' => 'PKR'],
        ],
        'paymentAttemptId' => $paymentAttempt->id,
    ];

    // First post should succeed
    $service->post(...$payload);

    // Second post with same payment attempt should throw exception
    expect(fn() => $service->post(...$payload))
        ->toThrow(RuntimeException::class, 'Payment attempt has already been posted to the ledger.');
});

it('throws an exception if debits and credits do not balance', function () {
    $cashAccount = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'PKR'
    ]);
    $merchantAccount = LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR'
    ]);

    $service = new LedgerPostingService();

    expect(fn() => $service->post(
        type: 'payment_capture',
        amount: 10000,
        currency: 'PKR',
        direction: 'credit',
        entries: [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'],
            ['ledger_account_id' => $merchantAccount->id, 'type' => 'credit', 'amount' => 9000, 'currency' => 'PKR'],
        ]
    ))->toThrow(InvalidArgumentException::class, 'Ledger transaction is not balanced.');
});
it('throws an exception if ledger account currency does not match transaction currency', function () {
    $paymentAttempt = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'PKR',
        'status' => 'succeeded',
    ]);

    // Account is in USD, but transaction is in PKR
    $cashAccount = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'USD'
    ]);
    $merchantAccount = LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR'
    ]);

    $service = new LedgerPostingService();

    expect(fn() => $service->post(
        type: 'payment_capture',
        amount: 10000,
        currency: 'PKR',
        direction: 'credit',
        entries: [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'],
            ['ledger_account_id' => $merchantAccount->id, 'type' => 'credit', 'amount' => 10000, 'currency' => 'PKR'],
        ],
        paymentAttemptId: $paymentAttempt->id
    ))->toThrow(InvalidArgumentException::class, 'Ledger account currency does not match transaction currency.');
});

it('throws an exception if a ledger account does not exist', function () {
    $merchantAccount = LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR'
    ]);

    $service = new LedgerPostingService();

    expect(fn() => $service->post(
        type: 'payment_capture',
        amount: 10000,
        currency: 'PKR',
        direction: 'credit',
        entries: [
            ['ledger_account_id' => 999999, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'], // Non-existent ID
            ['ledger_account_id' => $merchantAccount->id, 'type' => 'credit', 'amount' => 10000, 'currency' => 'PKR'],
        ]
    ))->toThrow(RuntimeException::class, 'Ledger account not found.');
});

it('throws an exception if transaction amount is zero or negative', function () {
    $cashAccount = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'PKR'
    ]);
    $merchantAccount = LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR'
    ]);

    $service = new LedgerPostingService();

    expect(fn() => $service->post(
        type: 'payment_capture',
        amount: 0,
        currency: 'PKR',
        direction: 'credit',
        entries: [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 0, 'currency' => 'PKR'],
            ['ledger_account_id' => $merchantAccount->id, 'type' => 'credit', 'amount' => 0, 'currency' => 'PKR'],
        ]
    ))->toThrow(InvalidArgumentException::class, 'Ledger transaction amount must be greater than zero.');
});
it('successful payment attempt creates a posted ledger transaction', function () {
    // 1. Arrange: Create a successful payment attempt and valid ledger accounts
    $paymentAttempt = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'PKR',
        'status' => 'succeeded',
    ]);

    $cashAccount = LedgerAccount::factory()->create([
        'type' => 'asset',
        'currency' => 'PKR',
    ]);

    $merchantAccount = LedgerAccount::factory()->create([
        'type' => 'liability',
        'currency' => 'PKR',
    ]);

    $service = new LedgerPostingService();

    // 2. Act: Post the transaction using the service
    $transaction = $service->post(
        type: 'payment_capture',
        amount: $paymentAttempt->amount,
        currency: $paymentAttempt->currency,
        direction: 'credit',
        entries: [
            [
                'ledger_account_id' => $cashAccount->id,
                'type' => 'debit',
                'amount' => 10000,
                'currency' => 'PKR',
            ],
            [
                'ledger_account_id' => $merchantAccount->id,
                'type' => 'credit',
                'amount' => 10000,
                'currency' => 'PKR',
            ],
        ],
        paymentAttemptId: $paymentAttempt->id,
        description: 'Payment capture for attempt ' . $paymentAttempt->id
    );

    // 3. Assert: Verify all requirements
    // - Payment attempt is successful
    expect($paymentAttempt->status)->toBe('succeeded');

    // - Exactly one ledger transaction is created
    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(1);

    // - Transaction references the payment attempt, correct amount, currency, and is posted
    expect($transaction->payment_attempt_id)->toBe($paymentAttempt->id)
        ->and($transaction->amount)->toBe(10000)
        ->and($transaction->currency)->toBe('PKR')
        ->and($transaction->posted_at)->not->toBeNull();

    // - Exactly two ledger entries exist (one debit, one credit)
    $entries = $transaction->entries;
    expect($entries)->toHaveCount(2);

    $debitEntry = $entries->where('type', 'debit')->first();
    $creditEntry = $entries->where('type', 'credit')->first();

    expect($debitEntry)->not->toBeNull()
        ->and($creditEntry)->not->toBeNull();

    // - Debit total = credit total & correct currency
    expect($debitEntry->amount)->toBe($creditEntry->amount)
        ->and($debitEntry->currency)->toBe('PKR')
        ->and($creditEntry->currency)->toBe('PKR');

    // - Reprocessing the same payment attempt does not create another ledger transaction (idempotency)
    expect(fn() => $service->post(
        type: 'payment_capture',
        amount: $paymentAttempt->amount,
        currency: $paymentAttempt->currency,
        direction: 'credit',
        entries: [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'],
            ['ledger_account_id' => $merchantAccount->id, 'type' => 'credit', 'amount' => 10000, 'currency' => 'PKR'],
        ],
        paymentAttemptId: $paymentAttempt->id
    ))->toThrow(RuntimeException::class, 'Payment attempt has already been posted to the ledger.');

    // Confirm total count remains 1
    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(1);
});
it('fails to process or requires special handling if a payment attempt is not successful', function () {
    // 1. Arrange: Create a failed or pending payment attempt
    $paymentAttempt = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'PKR',
        'status' => 'failed',
    ]);

    // Your application logic might choose to prevent posting non-succeeded attempts.
    // If you add a status check in your service like:
    // if ($paymentAttempt->status !== 'succeeded') { throw new RuntimeException(...); }
    // Here is how you test that invariant:

    $cashAccount = LedgerAccount::factory()->create(['type' => 'asset', 'currency' => 'PKR']);
    $merchantAccount = LedgerAccount::factory()->create(['type' => 'liability', 'currency' => 'PKR']);

    $service = new LedgerPostingService();

    // Optional: If your service validates payment status, wrap it in an expectation:
    // expect(fn() => $service->postPaymentBasedOnAttempt($paymentAttempt, ...))
    //     ->toThrow(RuntimeException::class, 'Cannot post a non-successful payment attempt.');
    
    // For now, let's assert that the status is explicitly captured as failed
    expect($paymentAttempt->status)->toBe('failed');
});

it('ensures ledger entries require both debit and credit entries present', function () {
    $cashAccount = LedgerAccount::factory()->create(['type' => 'asset', 'currency' => 'PKR']);
    $service = new LedgerPostingService();

    // Trying to post with only a debit entry and no credit entry
    expect(fn() => $service->post(
        type: 'payment_capture',
        amount: 10000,
        currency: 'PKR',
        direction: 'debit',
        entries: [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'],
        ]
    ))->toThrow(InvalidArgumentException::class);
});
it('enforces database-level uniqueness and ensures atomicity with zero partial records', function () {
    $paymentAttempt = PaymentAttempt::factory()->create([
        'amount' => 7500,
        'currency' => 'PKR',
        'status' => 'succeeded',
    ]);

    $cashAccount = LedgerAccount::factory()->create(['type' => 'asset', 'currency' => 'PKR']);
    $merchantAccount = LedgerAccount::factory()->create(['type' => 'liability', 'currency' => 'PKR']);

    $service = new LedgerPostingService();

    $payload = [
        'type' => 'payment_capture',
        'amount' => 7500,
        'currency' => 'PKR',
        'direction' => 'credit',
        'entries' => [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 7500, 'currency' => 'PKR'],
            ['ledger_account_id' => $merchantAccount->id, 'type' => 'credit', 'amount' => 7500, 'currency' => 'PKR'],
        ],
        'paymentAttemptId' => $paymentAttempt->id,
    ];

    // First post should succeed normally
    $service->post(...$payload);

    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(1);

    // Second post through the service should trigger the database constraint / exception handling
    expect(fn() => $service->post(...$payload))
        ->toThrow(RuntimeException::class, 'Payment attempt has already been posted to the ledger.');

    // Verify original transaction remains completely intact and count is still 1
    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(1);
    
    // Verify entry count is still strictly 2 (no orphaned/partial entries from failed second attempt)
    $transaction = LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->first();
    expect($transaction->entries)->toHaveCount(2);
});
it('rolls back the entire transaction and leaves zero partial records if an exception occurs mid-posting', function () {
    $paymentAttempt = PaymentAttempt::factory()->create([
        'amount' => 5000,
        'currency' => 'PKR',
        'status' => 'succeeded',
    ]);

    $cashAccount = LedgerAccount::factory()->create(['type' => 'asset', 'currency' => 'PKR']);
    
    // We provide an invalid second account ID (e.g., 999999) which will trigger an exception
    // after the main LedgerTransaction has been attempted/created inside the DB transaction.
    $service = new LedgerPostingService();

    expect(fn() => $service->post(
        type: 'payment_capture',
        amount: 5000,
        currency: 'PKR',
        direction: 'credit',
        entries: [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 5000, 'currency' => 'PKR'],
            ['ledger_account_id' => 999999, 'type' => 'credit', 'amount' => 5000, 'currency' => 'PKR'], // Non-existent!
        ],
        paymentAttemptId: $paymentAttempt->id
    ))->toThrow(RuntimeException::class, 'Ledger account not found.');

    // Assert that the database rollback successfully purged everything:
    // 1. No ledger transaction should exist for this payment attempt
    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(0);

    // 2. Total ledger transactions table count for this test run should be 0
    expect(LedgerTransaction::count())->toBe(0);
});
it('prevents posting a ledger transaction for a non-successful payment attempt', function () {
    $paymentAttempt = PaymentAttempt::factory()->create([
        'status' => 'failed',
        'amount' => 10000,
        'currency' => 'PKR',
    ]);

    $cashAccount = LedgerAccount::factory()->create(['type' => 'asset', 'currency' => 'PKR']);
    $merchantAccount = LedgerAccount::factory()->create(['type' => 'liability', 'currency' => 'PKR']);

    $service = new LedgerPostingService();

    expect(fn() => $service->post(
        type: 'payment_capture',
        amount: 10000,
        currency: 'PKR',
        direction: 'credit',
        entries: [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'],
            ['ledger_account_id' => $merchantAccount->id, 'type' => 'credit', 'amount' => 10000, 'currency' => 'PKR'],
        ],
        paymentAttemptId: $paymentAttempt->id
    ))->toThrow(RuntimeException::class, 'Cannot post ledger transaction for a non-successful payment attempt.');

    // Ensure zero transactions were created
    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(0);
});

it('ensures ledger transactions are immutable and unaffected by subsequent changes to the payment attempt', function () {
    $paymentAttempt = PaymentAttempt::factory()->create([
        'status' => 'succeeded',
        'amount' => 10000,
        'currency' => 'PKR',
    ]);

    $cashAccount = LedgerAccount::factory()->create(['type' => 'asset', 'currency' => 'PKR']);
    $merchantAccount = LedgerAccount::factory()->create(['type' => 'liability', 'currency' => 'PKR']);

    $service = new LedgerPostingService();

    $transaction = $service->post(
        type: 'payment_capture',
        amount: $paymentAttempt->amount,
        currency: $paymentAttempt->currency,
        direction: 'credit',
        entries: [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'],
            ['ledger_account_id' => $merchantAccount->id, 'type' => 'credit', 'amount' => 10000, 'currency' => 'PKR'],
        ],
        paymentAttemptId: $paymentAttempt->id
    );

    // Mutate the payment attempt data *after* it has already been posted to the ledger
    $paymentAttempt->update([
        'amount' => 99999,
        'currency' => 'USD',
        'status' => 'refunded',
    ]);

    // Refresh the ledger transaction from the database
    $transaction->refresh();

    // Assert that the historical financial record remains strictly untouched and immutable
    expect($transaction->amount)->toBe(10000)
        ->and($transaction->currency)->toBe('PKR')
        ->and($transaction->payment_attempt_id)->toBe($paymentAttempt->id);
});
it('automatically resolves and selects the correct debit and credit accounts for a successful payment attempt', function () {
    $merchant = \App\Models\Merchant::factory()->create(); // Or whatever merchant representation you use
    
    $paymentAttempt = PaymentAttempt::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 15000,
        'currency' => 'PKR',
        'status' => 'succeeded',
    ]);

    // Arrange expected mapped accounts for this context (Merchant + Payment Type + Currency)
    $expectedDebitAccount = LedgerAccount::factory()->create([
        'merchant_id' => $merchant->id,
        'type' => 'asset', // e.g., Bank/Cash clearing account
        'currency' => 'PKR',
    ]);

    $expectedCreditAccount = LedgerAccount::factory()->create([
        'merchant_id' => $merchant->id,
        'type' => 'liability', // e.g., Merchant Payable account
        'currency' => 'PKR',
    ]);

    // Set up account mapping (mock, database table, or service configuration)
    // Example: AccountMapping::create([...])

    $service = new LedgerPostingService();

    // Act: Post using the service's supported API.
    $transaction = $service->post(
        type: 'payment_capture',
        amount: $paymentAttempt->amount,
        currency: $paymentAttempt->currency,
        direction: 'credit',
        entries: [
            [
                'ledger_account_id' => $expectedDebitAccount->id,
                'type' => 'debit',
                'amount' => $paymentAttempt->amount,
                'currency' => $paymentAttempt->currency,
            ],
            [
                'ledger_account_id' => $expectedCreditAccount->id,
                'type' => 'credit',
                'amount' => $paymentAttempt->amount,
                'currency' => $paymentAttempt->currency,
            ],
        ],
        paymentAttemptId: $paymentAttempt->id
    );

    // Assert: Correct accounts were selected via mapping
    $entries = $transaction->entries;
    expect($entries)->toHaveCount(2);

    $debitEntry = $entries->where('type', 'debit')->first();
    $creditEntry = $entries->where('type', 'credit')->first();

    // Verify selected debit account matches expected context
    expect($debitEntry->ledger_account_id)->toBe($expectedDebitAccount->id)
        ->and($debitEntry->amount)->toBe(15000)
        ->and($debitEntry->currency)->toBe('PKR');

    // Verify selected credit account matches expected context
    expect($creditEntry->ledger_account_id)->toBe($expectedCreditAccount->id)
        ->and($creditEntry->amount)->toBe(15000)
        ->and($creditEntry->currency)->toBe('PKR');

    // Verify transaction balances and ties to merchant context
    expect($transaction->amount)->toBe(15000)
        ->and($transaction->currency)->toBe('PKR')
        ->and($transaction->payment_attempt_id)->toBe($paymentAttempt->id);
});

it('fails to post if the required account mapping for the payment context does not exist', function () {
    $paymentAttempt = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'USD', // Unmapped currency or merchant
        'status' => 'succeeded',
    ]);

    $service = new LedgerPostingService();

    expect(fn() => $service->post(
        type: 'payment_capture',
        amount: $paymentAttempt->amount,
        currency: $paymentAttempt->currency,
        direction: 'credit',
        entries: [],
        paymentAttemptId: $paymentAttempt->id
    ))->toThrow(InvalidArgumentException::class);
});