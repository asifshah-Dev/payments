<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\LedgerTransaction;
use App\Models\PaymentIntent;

class PaymentAttempt extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
    'payment_intent_id',
    'processor', // <--- Ensure this is here
    'status',
    'amount',
    'currency',
    'processor_reference_id',
    'failure_code',
    'failure_message',
];

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(
            PaymentIntent::class,
            'payment_intent_id',
            'id'
        );
    }

    public function ledgerTransactions(): HasMany
    {
        return $this->hasMany(
            LedgerTransaction::class,
            'payment_attempt_id',
            'id'
        );
    }
}