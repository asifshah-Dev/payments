<?php

use App\Http\Controllers\PaymentIntentController;
use App\Http\Middleware\MerchantAuthentication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/v1/payment-intents', [
    PaymentIntentController::class,
    'store',
])->middleware(MerchantAuthentication::class);

Route::get('/v1/test', function (Request $request) {
    return response()->json([
        'merchant_id' => $request->attributes->get('merchant')->id,
    ]);
})->middleware(MerchantAuthentication::class);