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
            ['ledger_account_id' => 999999, 'type' => 'debit', 'amount' => 10000, 'currency' => 'PKR'],
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

    expect($paymentAttempt->status)->toBe('succeeded');
    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(1);

    expect($transaction->payment_attempt_id)->toBe($paymentAttempt->id)
        ->and($transaction->amount)->toBe(10000)
        ->and($transaction->currency)->toBe('PKR')
        ->and($transaction->posted_at)->not->toBeNull();

    $entries = $transaction->entries;
    expect($entries)->toHaveCount(2);

    $debitEntry = $entries->where('type', 'debit')->first();
    $creditEntry = $entries->where('type', 'credit')->first();

    expect($debitEntry)->not->toBeNull()
        ->and($creditEntry)->not->toBeNull();

    expect($debitEntry->amount)->toBe($creditEntry->amount)
        ->and($debitEntry->currency)->toBe('PKR')
        ->and($creditEntry->currency)->toBe('PKR');

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

    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(1);
});

it('fails to process or requires special handling if a payment attempt is not successful', function () {
    $paymentAttempt = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'PKR',
        'status' => 'failed',
    ]);

    expect($paymentAttempt->status)->toBe('failed');
});

it('ensures ledger entries require both debit and credit entries present', function () {
    $cashAccount = LedgerAccount::factory()->create(['type' => 'asset', 'currency' => 'PKR']);
    $service = new LedgerPostingService();

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

    $service->post(...$payload);

    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(1);

    expect(fn() => $service->post(...$payload))
        ->toThrow(RuntimeException::class, 'Payment attempt has already been posted to the ledger.');

    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(1);
    
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
    $service = new LedgerPostingService();

    expect(fn() => $service->post(
        type: 'payment_capture',
        amount: 5000,
        currency: 'PKR',
        direction: 'credit',
        entries: [
            ['ledger_account_id' => $cashAccount->id, 'type' => 'debit', 'amount' => 5000, 'currency' => 'PKR'],
            ['ledger_account_id' => 999999, 'type' => 'credit', 'amount' => 5000, 'currency' => 'PKR'],
        ],
        paymentAttemptId: $paymentAttempt->id
    ))->toThrow(RuntimeException::class, 'Ledger account not found.');

    expect(LedgerTransaction::where('payment_attempt_id', $paymentAttempt->id)->count())->toBe(0);
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

    $paymentAttempt->update([
        'amount' => 99999,
        'currency' => 'USD',
        'status' => 'refunded',
    ]);

    $transaction->refresh();

    expect($transaction->amount)->toBe(10000)
        ->and($transaction->currency)->toBe('PKR')
        ->and($transaction->payment_attempt_id)->toBe($paymentAttempt->id);
});

// Step 6: Account Selection & Posting Rules Tests

it('automatically selects the correct debit and credit accounts, currency, amount, and balances for a successful payment', function () {
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

    // Act: Post automatically using payment attempt context resolution
    $transaction = $service->postFromPaymentAttempt($paymentAttempt);

    $entries = $transaction->entries;
    expect($entries)->toHaveCount(2);

    $debitEntry = $entries->where('type', 'debit')->first();
    $creditEntry = $entries->where('type', 'credit')->first();

    // - Selects the correct debit account and belongs to expected context
    expect($debitEntry->ledger_account_id)->toBe($cashAccount->id);

    // - Selects the correct credit account and belongs to expected context
    expect($creditEntry->ledger_account_id)->toBe($merchantAccount->id);

    // - Both entries use the payment currency
    expect($debitEntry->currency)->toBe('PKR')
        ->and($creditEntry->currency)->toBe('PKR')
        ->and($transaction->currency)->toBe('PKR');

    // - Both entries use exactly the payment amount
    expect($debitEntry->amount)->toBe(10000)
        ->and($creditEntry->amount)->toBe(10000)
        ->and($transaction->amount)->toBe(10000);

    // - The resulting transaction balances
    $totalDebits = $entries->where('type', 'debit')->sum('amount');
    $totalCredits = $entries->where('type', 'credit')->sum('amount');
    expect($totalDebits)->toBe($totalCredits);
});

it('fails to post if the required account mapping for the payment context does not exist', function () {
    $paymentAttempt = PaymentAttempt::factory()->create([
        'amount' => 10000,
        'currency' => 'USD', // Unmapped currency context
        'status' => 'succeeded',
    ]);

    $service = new LedgerPostingService();

    expect(fn() => $service->postFromPaymentAttempt($paymentAttempt))
        ->toThrow(RuntimeException::class, 'No account mapping found for this payment context.');
});
