<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        if ($transaction->getOriginal('posted_at') !== null) {
            throw new \InvalidArgumentException(
                "A posted ledger transaction cannot be modified."
            );
        }
    });

    static::deleting(function ($transaction) {
        if ($transaction->posted_at !== null) {
            throw new \InvalidArgumentException(
                "A posted ledger transaction cannot be deleted."
            );
        }
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