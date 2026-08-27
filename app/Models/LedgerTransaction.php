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
        static::creating(function (LedgerTransaction $transaction) {
            if ($transaction->posted_at) {
                $postedDate = $transaction->posted_at->format('YYYY-mm-dd'); // or standard date string
                
                if (\Illuminate\Support\Facades\Schema::hasTable('accounting_periods')) {
                    $isClosed = \Illuminate\Support\Facades\DB::table('accounting_periods')
                        ->where('status', 'closed')
                        ->whereDate('start_date', '<=', $transaction->posted_at)
                        ->whereDate('end_date', '>=', $transaction->posted_at)
                        ->exists();

                    if ($isClosed) {
                        throw new InvalidArgumentException("Cannot post a transaction into a closed accounting period.");
                    }
                }
            }
        });

        static::updating(function (LedgerTransaction $transaction) {
            // Check if existing or new posted_at falls in a closed period
            $targetDate = $transaction->posted_at ?? $transaction->getOriginal('posted_at');
            
            if ($targetDate && \Illuminate\Support\Facades\Schema::hasTable('accounting_periods')) {
                $isClosed = \Illuminate\Support\Facades\DB::table('accounting_periods')
                    ->where('status', 'closed')
                    ->whereDate('start_date', '<=', $targetDate)
                    ->whereDate('end_date', '>=', $targetDate)
                    ->exists();

                if ($isClosed) {
                    throw new InvalidArgumentException("Cannot modify a transaction within a closed accounting period.");
                }
            }

            // 1. Prevent moving a posted transaction back to unposted/pending (POSTED -> PENDING)
            if ($transaction->isDirty('posted_at')) {
                $originalPostedAt = $transaction->getOriginal('posted_at');
                
                if ($originalPostedAt !== null && is_null($transaction->posted_at)) {
                    throw new InvalidArgumentException(
                        "Cannot move a posted transaction back to a pending or unposted state."
                    );
                }
            }

            // 2. Prevent un-reversing or re-reversing once reversed (REVERSED -> ACTIVE or REVERSED -> REVERSED)
            if ($transaction->isDirty('reversed_at')) {
                if ($transaction->getOriginal('reversed_at') !== null) {
                    throw new InvalidArgumentException(
                        "A reversed transaction cannot be un-reversed or re-reversed."
                    );
                }
            }

            // 3. Block tampering with core financial attributes if posted
            if ($transaction->posted_at !== null) {
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
