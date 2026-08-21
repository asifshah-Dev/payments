<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ledger_transaction_id',
        'ledger_account_id',
        'type',
        'amount',
        'currency',
    ];

 protected static function booted()
{
    static::creating(function ($ledgerEntry) {
        $allowedTypes = ['debit', 'credit'];

        if (!in_array($ledgerEntry->type, $allowedTypes)) {
            throw new \InvalidArgumentException(
                "Invalid ledger entry type: {$ledgerEntry->type}"
            );
        }

        $transaction = $ledgerEntry->ledgerTransaction ?? \App\Models\LedgerTransaction::find($ledgerEntry->ledger_transaction_id);

        if ($transaction && $ledgerEntry->currency !== $transaction->currency) {
            throw new \InvalidArgumentException(
                "Ledger entry currency must match transaction currency."
            );
        }

        $account = $ledgerEntry->ledgerAccount ?? \App\Models\LedgerAccount::find($ledgerEntry->ledger_account_id);

        if ($account && $ledgerEntry->currency !== $account->currency) {
            throw new \InvalidArgumentException(
                "Ledger entry currency must match ledger account currency."
            );
        }

        if ($account && $account->status !== 'active') {
            throw new \InvalidArgumentException(
                "Cannot create entry on a {$account->status} ledger account."
            );
        }
    });

    static::updating(function ($ledgerEntry) {
        // 1. Verify parent transaction is not posted (posted entries cannot be modified)
        $transaction = $ledgerEntry->ledgerTransaction ?? \App\Models\LedgerTransaction::find($ledgerEntry->ledger_transaction_id);

        if ($transaction && $transaction->posted_at !== null) {
            throw new \InvalidArgumentException(
                "A ledger entry belonging to a posted transaction cannot be modified."
            );
        }

        $allowedTypes = ['debit', 'credit'];

        if ($ledgerEntry->isDirty('type') && !in_array($ledgerEntry->type, $allowedTypes)) {
            throw new \InvalidArgumentException(
                "Invalid ledger entry type: {$ledgerEntry->type}"
            );
        }

        if (($ledgerEntry->isDirty('currency') || $ledgerEntry->isDirty('ledger_transaction_id')) && $transaction && $ledgerEntry->currency !== $transaction->currency) {
            throw new \InvalidArgumentException(
                "Ledger entry currency must match transaction currency."
            );
        }

        $account = $ledgerEntry->ledgerAccount ?? \App\Models\LedgerAccount::find($ledgerEntry->ledger_account_id);

        if (($ledgerEntry->isDirty('currency') || $ledgerEntry->isDirty('ledger_account_id')) && $account && $ledgerEntry->currency !== $account->currency) {
            throw new \InvalidArgumentException(
                "Ledger entry currency must match ledger account currency."
            );
        }

        if ($account && $account->status !== 'active') {
            throw new \InvalidArgumentException(
                "Cannot create entry on a {$account->status} ledger account."
            );
        }
    });

    static::deleting(function ($ledgerEntry) {
    $transaction = $ledgerEntry->ledgerTransaction ?? \App\Models\LedgerTransaction::find($ledgerEntry->ledger_transaction_id);

    if ($transaction && $transaction->posted_at !== null) {
        throw new \InvalidArgumentException(
            "A ledger entry belonging to a posted transaction cannot be deleted."
        );
    }
});
}

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(
            LedgerTransaction::class,
            'ledger_transaction_id'
        );
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            LedgerAccount::class,
            'ledger_account_id'
        );
    }
}