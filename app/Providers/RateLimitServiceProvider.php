<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('refunds', function ($request) {
            $merchantId = optional($request->attributes->get('merchant'))->id;

            return Limit::perMinute(60)->by($merchantId ?: $request->ip());
        });
    }
}