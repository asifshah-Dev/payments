<?php
// app/Models/Merchant.php

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

    protected $hidden = [
        'api_key_hash', // never serialize this
    ];

    public function paymentIntents(): HasMany
    {
        return $this->hasMany(PaymentIntent::class, 'merchant_id', 'id');
    }

    public function ledgerAccounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class, 'merchant_id', 'id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'merchant_id', 'id');
    }

    /**
     * Generate a fresh API key, store only its hash, return the raw key.
     * The caller is responsible for showing the raw key to the merchant once.
     */
    public function issueApiKey(): string
    {
        $raw = 'sk_' . (app()->environment('production') ? 'live_' : 'test_')
             . \Illuminate\Support\Str::random(40);

        $this->forceFill([
            'api_key_hash' => hash('sha256', $raw),
        ])->save();

        return $raw;
    }

    public static function findByApiKey(string $rawKey): ?self
    {
        return static::where('api_key_hash', hash('sha256', $rawKey))->first();
    }
}