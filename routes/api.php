<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\MerchantAuthentication;

Route::get('/v1/test', function (Request $request) {
    $merchant = $request->attributes->get('merchant');

    return response()->json([
        'message' => 'API is working.',
        'merchant_id' => $merchant->id,
    ]);
})->middleware(MerchantAuthentication::class);