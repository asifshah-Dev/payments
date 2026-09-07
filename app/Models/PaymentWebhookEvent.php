<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    use HasUuids;

    protected $table = 'payment_webhook_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'processor',
        'event_id',
        'event_type',
        'payload',
        'status',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}