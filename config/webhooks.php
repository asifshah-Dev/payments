<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Replay window
    |--------------------------------------------------------------------------
    |
    | Maximum allowed age (in seconds) for an incoming webhook, measured
    | against the `t` value in the signature header. Stripe recommends
    | 300 seconds (5 minutes). Rejecting older events prevents an attacker
    | who captured a valid webhook from replaying it later.
    |
    */
    'replay_window_seconds' => env('WEBHOOK_REPLAY_WINDOW_SECONDS', 300),
];