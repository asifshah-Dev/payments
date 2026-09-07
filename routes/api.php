<?php

use App\Http\Controllers\Api\V1\PaymentIntentController;
use App\Http\Middleware\MerchantAuthentication;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WebhookController;

/*
|--------------------------------------------------------------------------
| Payment Intent API
|--------------------------------------------------------------------------
*/

Route::middleware(MerchantAuthentication::class)->prefix('v1')->group(function () {
    Route::post('/payment-intents', [PaymentIntentController::class, 'store']);
    Route::get('/payment-intents/{id}', [PaymentIntentController::class, 'show']);
});
Route::post('/v1/webhooks/{processor}', [WebhookController::class, 'handle']);