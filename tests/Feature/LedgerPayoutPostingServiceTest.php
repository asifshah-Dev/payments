<?php

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\LedgerAccount;
use App\Models\LedgerAccountMapping;
use App\Models\LedgerTransaction;
use App\Models\Merchant;
use App\Models\Payout;
use App\Services\LedgerPostingService;
use RuntimeException;
use InvalidArgumentException;

it('successfully posts a completed payout using account mappings', function () {
    $merchant = Merchant::factory()->create();

    $merchantPayable = LedgerAccount::create([
        'name' => 'Merchant Payable - ' . $merchant->id,
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    $platformClearing = LedgerAccount::create([
        'name' => 'Platform Gateway Clearing',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    LedgerAccountMapping::create([
        'context' => 'merchant_payout',
        'currency' => 'PKR',
        'debit_account_role' => 'merchant_payable',
        'credit_account_role' => 'platform_gateway_clearing',
    ]);

    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'amount' => 5000,
        'currency' => 'PKR',
        'status' => 'completed',
    ]);

    $transaction = app(LedgerPostingService::class)->postFromPayout($payout);

    expect($transaction)->toBeInstanceOf(LedgerTransaction::class)
        ->and($transaction->amount)->toBe(5000)
        ->and($transaction->currency)->toBe('PKR')
        ->and($transaction->posted_at)->not->toBeNull();

    $entries = $transaction->entries;
    expect($entries)->toHaveCount(2);

    $debit = $entries->where('type', 'debit')->first();
    $credit = $entries->where('type', 'credit')->first();

    // Merchant payable is debited (liability reduced)
    expect($debit->ledger_account_id)->toBe($merchantPayable->id)
        ->and($debit->amount)->toBe(5000);

    // Platform clearing is credited (asset reduced)
    expect($credit->ledger_account_id)->toBe($platformClearing->id)
        ->and($credit->amount)->toBe(5000);

    expect($entries->where('type', 'debit')->sum('amount'))
        ->toBe($entries->where('type', 'credit')->sum('amount'));
});

it('refuses to post a non-completed payout', function () {
    $merchant = Merchant::factory()->create();
    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'status' => 'pending',
    ]);

    expect(fn () => app(LedgerPostingService::class)->postFromPayout($payout))
        ->toThrow(RuntimeException::class, 'Cannot post ledger transaction for a non-completed payout.');
});

it('prevents double-posting the same payout', function () {
    $merchant = Merchant::factory()->create();

    LedgerAccount::create([
        'name' => 'Merchant Payable',
        'type' => 'liability',
        'currency' => 'PKR',
        'merchant_id' => $merchant->id,
        'status' => 'active',
    ]);

    LedgerAccount::create([
        'name' => 'Platform Clearing',
        'type' => 'asset',
        'currency' => 'PKR',
        'merchant_id' => null,
        'status' => 'active',
    ]);

    LedgerAccountMapping::create([
        'context' => 'merchant_payout',
        'currency' => 'PKR',
        'debit_account_role' => 'merchant_payable',
        'credit_account_role' => 'platform_gateway_clearing',
    ]);

    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'status' => 'completed',
    ]);

    $service = app(LedgerPostingService::class);

    // First post should succeed
    $service->postFromPayout($payout);

    // Second post should fail idempotency / duplicate check
    expect(fn () => $service->postFromPayout($payout))
        ->toThrow(RuntimeException::class);
});

it('fails if payout mapping is missing', function () {
    $merchant = Merchant::factory()->create();
    $payout = Payout::factory()->create([
        'merchant_id' => $merchant->id,
        'status' => 'completed',
        'currency' => 'USD',
    ]);

    expect(fn () => app(LedgerPostingService::class)->postFromPayout($payout))
        ->toThrow(RuntimeException::class);
});