<?php

use App\Http\Controllers\PaymentIntentController;
use App\Http\Middleware\MerchantAuthentication;
use Illuminate\Support\Facades\Route;

Route::post('/v1/payment-intents', [
    PaymentIntentController::class,
    'store',
])->middleware(MerchantAuthentication::class);