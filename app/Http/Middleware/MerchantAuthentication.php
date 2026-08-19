<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantAuthentication
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
{
    $authorization = $request->header('Authorization');

    if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
        return response()->json([
            'message' => 'Unauthenticated.',
        ], 401);
    }

    $apiKey = substr($authorization, 7);

    if ($apiKey === '') {
        return response()->json([
            'message' => 'Unauthenticated.',
        ], 401);
    }

    $apiKeyHash = hash('sha256', $apiKey);

    $merchant = \App\Models\Merchant::where(
        'api_key_hash',
        $apiKeyHash
    )->first();

    if (!$merchant) {
        return response()->json([
            'message' => 'Unauthenticated.',
        ], 401);
    }

    if ($merchant->status !== 'active') {
        return response()->json([
            'message' => 'Merchant account is inactive.',
        ], 403);
    }

    $request->attributes->set('merchant', $merchant);

    return $next($request);
}
}
