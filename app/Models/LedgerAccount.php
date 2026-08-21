<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
class LedgerAccount extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'merchant_id',
        'name',
        'type',
        'currency',
        'status',
    ];
    protected static function booted(): void
    {
        $allowedTypes = [
            'asset',
            'liability',
            'revenue',
            'expense',
        ];

        $allowedStatuses = [
            'active',
            'inactive',
            'frozen',
            'closed',
        ];

        static::creating(function ($ledgerAccount) use ($allowedTypes, $allowedStatuses) {
            if (!in_array($ledgerAccount->type, $allowedTypes)) {
                throw new InvalidArgumentException(
                    "Invalid ledger account type: {$ledgerAccount->type}"
                );
            }

            if (!in_array($ledgerAccount->status, $allowedStatuses)) {
                throw new InvalidArgumentException(
                    "Invalid ledger account status: {$ledgerAccount->status}"
                );
            }

            $ledgerAccount->currency = strtoupper($ledgerAccount->currency);

            if (!preg_match('/^[A-Z]{3}$/', $ledgerAccount->currency)) {
                throw new InvalidArgumentException(
                    "Invalid currency code: {$ledgerAccount->currency}"
                );
            }
        });

        static::updating(function ($ledgerAccount) use ($allowedTypes, $allowedStatuses) {
            if (
                $ledgerAccount->isDirty('type') &&
                !in_array($ledgerAccount->type, $allowedTypes)
            ) {
                throw new InvalidArgumentException(
                    "Invalid ledger account type: {$ledgerAccount->type}"
                );
            }

            if (
                $ledgerAccount->isDirty('status') &&
                !in_array($ledgerAccount->status, $allowedStatuses)
            ) {
                throw new InvalidArgumentException(
                    "Invalid ledger account status: {$ledgerAccount->status}"
                );
            }

            if ($ledgerAccount->isDirty('currency')) {
                $ledgerAccount->currency = strtoupper($ledgerAccount->currency);

                if (!preg_match('/^[A-Z]{3}$/', $ledgerAccount->currency)) {
                    throw new InvalidArgumentException(
                        "Invalid currency code: {$ledgerAccount->currency}"
                    );
                }
            }
        });
    }
    
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function balance(): int
{
    $debits = (int) $this->entries()
        ->where('currency', $this->currency)
        ->where('type', 'debit')
        ->sum('amount');

    $credits = (int) $this->entries()
        ->where('currency', $this->currency)
        ->where('type', 'credit')
        ->sum('amount');

    return in_array($this->type, ['asset', 'expense'], true)
        ? $debits - $credits
        : $credits - $debits;
}
}