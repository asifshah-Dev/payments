<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Replay window
    |--------------------------------------------------------------------------
    */
    'replay_window_seconds' => env('WEBHOOK_REPLAY_WINDOW_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Per-processor signing secrets
    |--------------------------------------------------------------------------
    |
    | Each entry maps a processor name (as it appears in the webhook URL)
    | to the shared secret used to compute HMAC-SHA256 signatures.
    */
    'secrets' => [
        'stripe' => env('STRIPE_WEBHOOK_SECRET'),
        'paypal' => env('PAYPAL_WEBHOOK_SECRET'),
    ],
];