<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Models\PaymentAttempt;

class LedgerTransaction extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'type',
        'payment_attempt_id',
        'reference_type',
        'currency',
        'reference_id',
        'description',
        'posted_at',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(
            PaymentAttempt::class,
            'payment_attempt_id'
        );
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}