<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LedgerTransaction extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'payment_attempt_id',
        'type',
        'amount',
        'currency',
        'direction',
        'reference_type',
        'reference_id',
        'posted_at',
        'description',
    ];

    protected $casts = [
        'amount' => 'integer',
        'posted_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::updating(function ($transaction) {
            // If it was already posted before this update, block modification
            if ($transaction->getOriginal('posted_at') !== null) {
                throw new InvalidArgumentException(
                    "A posted ledger transaction cannot be modified."
                );
            }
        });

        static::deleting(function ($transaction) {
            if ($transaction->posted_at !== null) {
                throw new InvalidArgumentException(
                    "A posted transaction cannot be deleted."
                );
            }
        });
    }

    /**
     * Post the transaction atomically if its entries are balanced and complete.
     */
    public function post()
    {
        return DB::transaction(function () {
            $debitEntries = $this->entries()->where('type', 'debit')->get();
            $creditEntries = $this->entries()->where('type', 'credit')->get();

            // Enforce double-entry accounting minimum requirements
            if ($debitEntries->isEmpty() || $creditEntries->isEmpty()) {
                throw new InvalidArgumentException(
                    "Cannot post a transaction without at least one debit and one credit entry."
                );
            }

            $totalDebits = $debitEntries->sum('amount');
            $totalCredits = $creditEntries->sum('amount');

            // Enforce balance invariant
            if ($totalDebits !== $totalCredits) {
                throw new InvalidArgumentException(
                    "Cannot post an unbalanced transaction. Total Debits: {$totalDebits}, Total Credits: {$totalCredits}"
                );
            }

            // Stamp posted_at
            $this->update([
                'posted_at' => now(),
            ]);
        });
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(
            PaymentAttempt::class,
            'payment_attempt_id',
            'id'
        );
    }

    public function entries(): HasMany
    {
        return $this->hasMany(
            LedgerEntry::class,
            'ledger_transaction_id',
            'id'
        );
    }
}