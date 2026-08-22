<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use InvalidArgumentException;

class LedgerEntryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_debit_ledger_entry_cannot_have_an_amount_of_zero()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 0,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'asset',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 0,
            'type' => 'debit',
            'currency' => 'PKR',
        ]);
    }

    /** @test */
    public function a_credit_ledger_entry_cannot_have_an_amount_of_zero()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 0,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'liability',
        ]);

        $this->expectException(InvalidArgumentException::class);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 0,
            'type' => 'credit',
            'currency' => 'PKR',
        ]);
    }
    /** @test */
    public function a_ledger_entry_cannot_have_a_negative_amount()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'asset',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => -500, // Negative amount
            'type' => 'debit',
            'currency' => 'PKR',
        ]);
    }

    /** @test */
    public function a_valid_ledger_entry_with_a_positive_amount_can_be_created()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'PKR',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'PKR',
            'type' => 'asset',
        ]);

        $entry = $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000, // Valid positive amount
            'type' => 'debit',
            'currency' => 'PKR',
        ]);

        $this->assertNotNull($entry->id);
        $this->assertEquals(1000, $entry->amount);
        $this->assertEquals('debit', $entry->type);
    }
    /** @test */
    public function ledger_entry_normalizes_currency_to_uppercase()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'USD',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'USD',
            'type' => 'asset',
        ]);

        $entry = $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'usd', // Lowercase input
        ]);

        // Assert it was normalized to uppercase
        $this->assertEquals('USD', $entry->currency);
    }
    /** @test */
    public function ledger_entry_currency_must_match_transaction_currency()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'USD',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'USD',
            'type' => 'asset',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'EUR', // Mismatched currency
        ]);
    }

    /** @test */
    public function ledger_entry_currency_must_match_account_currency()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'USD',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'EUR', // Account currency differs from transaction
            'type' => 'asset',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'USD',
        ]);
    }

    /** @test */
    public function cannot_create_entry_on_an_inactive_ledger_account()
    {
        $transaction = LedgerTransaction::create([
            'type' => 'transfer',
            'amount' => 1000,
            'currency' => 'USD',
            'direction' => 'credit',
            'posted_at' => null,
        ]);

        $account = LedgerAccount::factory()->create([
            'currency' => 'USD',
            'type' => 'asset',
            'status' => 'inactive', // Inactive account
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $transaction->entries()->create([
            'ledger_account_id' => $account->id,
            'amount' => 1000,
            'type' => 'debit',
            'currency' => 'USD',
        ]);
    }
}