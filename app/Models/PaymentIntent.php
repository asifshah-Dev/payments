<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\PaymentAttempt;
class PaymentIntent extends Model
{
        use HasFactory;
        use HasUuids;

        

    protected $fillable = [
        'merchant_id', 'amount', 'currency', 'description', 
        'status', 'idempotency_key', 'request_hash'
    ];

    /**
     * Get the merchant that owns this payment intent.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }
    public function paymentAttempts(): HasMany
{
    return $this->hasMany(
        PaymentAttempt::class,
        'payment_intent_id',
        'id'
    );
}
}
