<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncomingWebhookLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'processor',
        'processor_event_id',
        'event_type',
        'payload',
        'status',
        'attempt_count',
        'error_message',
        'locked_at',
        'locked_by',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'locked_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}