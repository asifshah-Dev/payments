<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    App\Providers\RateLimitServiceProvider::class,   // <-- add

];
