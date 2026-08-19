<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\PaymentIntent;
class Merchant extends Model
{
    use HasFactory;
    use HasUuids;

    public $guarded = [];

    public function paymentIntents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class, 'merchant_id', 'id');
    }
}
