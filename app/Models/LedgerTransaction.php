<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;

class LedgerTransaction extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    /**
     * The "booted" method of the model.
     */
   /**
     * The "booted" method of the model.
     */
    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updating(function (LedgerTransaction $transaction) {
            if ($transaction->posted_at !== null) {
                // Only block updates if core financial or source attributes are being modified
                $tamperedAttributes = ['amount', 'type', 'currency', 'direction', 'source_type', 'source_id'];

                foreach ($tamperedAttributes as $attribute) {
                    if ($transaction->isDirty($attribute)) {
                        throw new InvalidArgumentException('A posted ledger transaction cannot be modified.');
                    }
                }
            }
        });

        static::deleting(function (LedgerTransaction $transaction) {
            if ($transaction->posted_at !== null) {
                throw new InvalidArgumentException('A posted ledger transaction cannot be deleted.');
            }
        });
    }

    /**
     * Get the parent source model (e.g., PaymentAttempt, Refund, Payout).
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the ledger entries associated with this transaction.
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
