<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundAttempt extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected $casts = [
        'raw_response' => 'array',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
