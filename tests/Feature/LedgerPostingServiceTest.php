
<?php

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Services\LedgerPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

uses(RefreshDatabase::class);

function createLedgerPostingPaymentAttempt(): PaymentAttempt
{
    $merchant = Merchant::create([
        'name' => 'Ledger Posting Test Merchant',
        'email' => Str::uuid() . '@example.com',
        'status' => 'active',
        'api_key_hash' => hash('sha256', Str::random(40)),
    ]);

    $paymentIntent = PaymentIntent::create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'USD',
        'description' => 'Ledger posting test',
        'status' => 'succeeded',
        'idempotency_key' => Str::uuid()->toString(),
        'request_hash' => hash('sha256', Str::random(20)),
    ]);

    return PaymentAttempt::create([
        'payment_intent_id' => $paymentIntent->id,
        'processor' => 'stripe',
        'status' => 'succeeded',
        'amount' => 5000,
        'currency' => 'USD',
    ]);
}

function createLedgerPostingAccounts(): array
{
    return [
        'debit' => LedgerAccount::create([
            'name' => 'Customer Receivable',
            'type' => 'asset',
            'currency' => 'USD',
            'status' => 'active',
        ]),
        'credit' => LedgerAccount::create([
            'name' => 'Merchant Settlement',
            'type' => 'liability',
            'currency' => 'USD',
            'status' => 'active',
        ]),
    ];
}

test('creates a balanced ledger transaction', function () {
    $attempt = createLedgerPostingPaymentAttempt();
    $accounts = createLedgerPostingAccounts();

    $transaction = app(LedgerPostingService::class)->post(
        type: 'payment',
        amount: 5000,
        currency: 'USD',
        direction: 'credit',
        entries: [
            [
                'ledger_account_id' => $accounts['debit']->id,
                'type' => 'debit',
                'amount' => 5000,
                'currency' => 'USD',
            ],
            [
                'ledger_account_id' => $accounts['credit']->id,
                'type' => 'credit',
                'amount' => 5000,
                'currency' => 'USD',
            ],
        ],
        paymentAttemptId: $attempt->id,
        description: 'Payment received',
    );

    expect($transaction)
        ->toBeInstanceOf(LedgerTransaction::class)
        ->and($transaction->type)->toBe('payment')
        ->and($transaction->amount)->toBe(5000)
        ->and($transaction->currency)->toBe('USD')
        ->and($transaction->direction)->toBe('credit');

    expect(LedgerTransaction::count())->toBe(1);
    expect(LedgerEntry::count())->toBe(2);
});

test('creates debit and credit ledger entries', function () {
    $accounts = createLedgerPostingAccounts();

    $transaction = app(LedgerPostingService::class)->post(
        type: 'payment',
        amount: 5000,
        currency: 'USD',
        direction: 'credit',
        entries: [
            [
                'ledger_account_id' => $accounts['debit']->id,
                'type' => 'debit',
                'amount' => 5000,
                'currency' => 'USD',
            ],
            [
                'ledger_account_id' => $accounts['credit']->id,
                'type' => 'credit',
                'amount' => 5000,
                'currency' => 'USD',
            ],
        ],
    );

    $entries = LedgerEntry::where(
        'ledger_transaction_id',
        $transaction->id
    )->get();

    expect($entries)->toHaveCount(2);

    expect(
        $entries->where('type', 'debit')->sum('amount')
    )->toBe(5000);

    expect(
        $entries->where('type', 'credit')->sum('amount')
    )->toBe(5000);
});

test('rejects an unbalanced ledger transaction', function () {
    $accounts = createLedgerPostingAccounts();

    expect(fn () =>
        app(LedgerPostingService::class)->post(
            type: 'payment',
            amount: 5000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => $accounts['debit']->id,
                    'type' => 'debit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => $accounts['credit']->id,
                    'type' => 'credit',
                    'amount' => 4000,
                    'currency' => 'USD',
                ],
            ],
        )
    )->toThrow(InvalidArgumentException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('rejects a ledger transaction without a debit entry', function () {
    $accounts = createLedgerPostingAccounts();

    expect(fn () =>
        app(LedgerPostingService::class)->post(
            type: 'payment',
            amount: 5000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => $accounts['credit']->id,
                    'type' => 'credit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
            ],
        )
    )->toThrow(InvalidArgumentException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('rejects a ledger transaction without a credit entry', function () {
    $accounts = createLedgerPostingAccounts();

    expect(fn () =>
        app(LedgerPostingService::class)->post(
            type: 'payment',
            amount: 5000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => $accounts['debit']->id,
                    'type' => 'debit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
            ],
        )
    )->toThrow(InvalidArgumentException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('rejects entries with different currencies', function () {
    $accounts = createLedgerPostingAccounts();

    expect(fn () =>
        app(LedgerPostingService::class)->post(
            type: 'payment',
            amount: 5000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => $accounts['debit']->id,
                    'type' => 'debit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => $accounts['credit']->id,
                    'type' => 'credit',
                    'amount' => 5000,
                    'currency' => 'EUR',
                ],
            ],
        )
    )->toThrow(InvalidArgumentException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('rejects transaction currency that does not match entry currency', function () {
    $accounts = createLedgerPostingAccounts();

    expect(fn () =>
        app(LedgerPostingService::class)->post(
            type: 'payment',
            amount: 5000,
            currency: 'EUR',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => $accounts['debit']->id,
                    'type' => 'debit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => $accounts['credit']->id,
                    'type' => 'credit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
            ],
        )
    )->toThrow(InvalidArgumentException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('links the ledger transaction to a payment attempt', function () {
    $attempt = createLedgerPostingPaymentAttempt();
    $accounts = createLedgerPostingAccounts();

    $transaction = app(LedgerPostingService::class)->post(
        type: 'payment',
        amount: 5000,
        currency: 'USD',
        direction: 'credit',
        entries: [
            [
                'ledger_account_id' => $accounts['debit']->id,
                'type' => 'debit',
                'amount' => 5000,
                'currency' => 'USD',
            ],
            [
                'ledger_account_id' => $accounts['credit']->id,
                'type' => 'credit',
                'amount' => 5000,
                'currency' => 'USD',
            ],
        ],
        paymentAttemptId: $attempt->id,
    );

    expect($transaction->payment_attempt_id)
        ->toBe($attempt->id);

    expect($transaction->paymentAttempt)
        ->toBeInstanceOf(PaymentAttempt::class)
        ->and($transaction->paymentAttempt->id)
        ->toBe($attempt->id);
});

test('stores reference information', function () {
    $accounts = createLedgerPostingAccounts();

    $referenceId = Str::uuid()->toString();

    $transaction = app(LedgerPostingService::class)->post(
        type: 'payment',
        amount: 5000,
        currency: 'USD',
        direction: 'credit',
        entries: [
            [
                'ledger_account_id' => $accounts['debit']->id,
                'type' => 'debit',
                'amount' => 5000,
                'currency' => 'USD',
            ],
            [
                'ledger_account_id' => $accounts['credit']->id,
                'type' => 'credit',
                'amount' => 5000,
                'currency' => 'USD',
            ],
        ],
        referenceType: 'payment_attempt',
        referenceId: $referenceId,
        description: 'Payment received',
    );

    expect($transaction->reference_type)
        ->toBe('payment_attempt')
        ->and($transaction->reference_id)
        ->toBe($referenceId)
        ->and($transaction->description)
        ->toBe('Payment received');
});

test('sets posted at when the ledger transaction is posted', function () {
    $accounts = createLedgerPostingAccounts();

    $transaction = app(LedgerPostingService::class)->post(
        type: 'payment',
        amount: 5000,
        currency: 'USD',
        direction: 'credit',
        entries: [
            [
                'ledger_account_id' => $accounts['debit']->id,
                'type' => 'debit',
                'amount' => 5000,
                'currency' => 'USD',
            ],
            [
                'ledger_account_id' => $accounts['credit']->id,
                'type' => 'credit',
                'amount' => 5000,
                'currency' => 'USD',
            ],
        ],
    );

    expect($transaction->posted_at)
        ->not->toBeNull()
        ->and($transaction->posted_at)
        ->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('rejects an invalid ledger entry type', function () {
    $accounts = createLedgerPostingAccounts();

    expect(fn () =>
        app(LedgerPostingService::class)->post(
            type: 'payment',
            amount: 5000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => $accounts['debit']->id,
                    'type' => 'invalid',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => $accounts['credit']->id,
                    'type' => 'credit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
            ],
        )
    )->toThrow(InvalidArgumentException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('rejects zero amount ledger entries', function () {
    $accounts = createLedgerPostingAccounts();

    expect(fn () =>
        app(LedgerPostingService::class)->post(
            type: 'payment',
            amount: 0,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => $accounts['debit']->id,
                    'type' => 'debit',
                    'amount' => 0,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => $accounts['credit']->id,
                    'type' => 'credit',
                    'amount' => 0,
                    'currency' => 'USD',
                ],
            ],
        )
    )->toThrow(InvalidArgumentException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('rejects nonexistent ledger account', function () {
    $accounts = createLedgerPostingAccounts();

    expect(fn () =>
        app(LedgerPostingService::class)->post(
            type: 'payment',
            amount: 5000,
            currency: 'USD',
            direction: 'credit',
            entries: [
                [
                    'ledger_account_id' => Str::uuid()->toString(),
                    'type' => 'debit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
                [
                    'ledger_account_id' => $accounts['credit']->id,
                    'type' => 'credit',
                    'amount' => 5000,
                    'currency' => 'USD',
                ],
            ],
        )
    )->toThrow(RuntimeException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
});

test('prevents duplicate ledger posting for the same payment attempt', function () {
    $attempt = createLedgerPostingPaymentAttempt();
    $accounts = createLedgerPostingAccounts();

    $service = app(LedgerPostingService::class);

    $entries = [
        [
            'ledger_account_id' => $accounts['debit']->id,
            'type' => 'debit',
            'amount' => 5000,
            'currency' => 'USD',
        ],
        [
            'ledger_account_id' => $accounts['credit']->id,
            'type' => 'credit',
            'amount' => 5000,
            'currency' => 'USD',
        ],
    ];

    $first = $service->post(
        type: 'payment',
        amount: 5000,
        currency: 'USD',
        direction: 'credit',
        entries: $entries,
        paymentAttemptId: $attempt->id,
    );

    expect(fn () =>
        $service->post(
            type: 'payment',
            amount: 5000,
            currency: 'USD',
            direction: 'credit',
            entries: $entries,
            paymentAttemptId: $attempt->id,
        )
    )->toThrow(RuntimeException::class);

    expect(LedgerTransaction::count())->toBe(1);
    expect(LedgerEntry::count())->toBe(2);
});

test('allows a ledger transaction without a payment attempt', function () {
    $accounts = createLedgerPostingAccounts();

    $transaction = app(LedgerPostingService::class)->post(
        type: 'adjustment',
        amount: 1000,
        currency: 'USD',
        direction: 'credit',
        entries: [
            [
                'ledger_account_id' => $accounts['debit']->id,
                'type' => 'debit',
                'amount' => 1000,
                'currency' => 'USD',
            ],
            [
                'ledger_account_id' => $accounts['credit']->id,
                'type' => 'credit',
                'amount' => 1000,
                'currency' => 'USD',
            ],
        ],
        description: 'Manual adjustment',
    );

    expect($transaction->payment_attempt_id)->toBeNull();
    expect(LedgerEntry::count())->toBe(2);
});
