<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
}