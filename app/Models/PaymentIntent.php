<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use DomainException;

class PaymentIntent extends Model
{
    use HasFactory;

    protected $table = 'payment_intents';

    /*
    |--------------------------------------------------------------------------
    | UUID configuration
    |--------------------------------------------------------------------------
    */

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (PaymentIntent $paymentIntent) {
            if (!$paymentIntent->id) {
                $paymentIntent->id = (string) Str::uuid();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Mass assignable fields
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'merchant_id',
        'amount',
        'currency',
        'description',
        'status',
        'idempotency_key',
        'request_hash',
    ];

    /*
    |--------------------------------------------------------------------------
    | Hidden internal fields
    |--------------------------------------------------------------------------
    */

    protected $hidden = [
        'idempotency_key',
        'request_hash',
    ];

    /*
    |--------------------------------------------------------------------------
    | State Machine Transitions
    |--------------------------------------------------------------------------
    */

    protected array $allowedTransitions = [
        'pending' => ['processing', 'failed', 'canceled'],
        'processing' => ['succeeded', 'failed'],
        'succeeded' => [],
        'failed' => ['processing'], // Allow retries from a failed state
        'canceled' => [],
    ];

    public function transitionTo(string $newStatus): void
    {
        $currentStatus = $this->status;
        $allowed = $this->allowedTransitions[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new DomainException(
                "Invalid status transition from '{$currentStatus}' to '{$newStatus}'."
            );
        }

        $this->update(['status' => $newStatus]);
    }

    /*
    |--------------------------------------------------------------------------
    | Merchant relationship
    |--------------------------------------------------------------------------
    */

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(
            Merchant::class,
            'merchant_id'
        );
    }
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(
            PaymentAttempt::class,
            'payment_intent_id',
            'id'
        );
    }
    public function attempts()
{
    return $this->hasMany(PaymentAttempt::class);
}
}