<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    use HasFactory;
    use HasUuids;

    public $guarded = [];

    public function paymentIntent(): HasMany
    {
        return $this->hasMany(PaymentIntent::class, 'merchant_id', 'id');
    }
}
