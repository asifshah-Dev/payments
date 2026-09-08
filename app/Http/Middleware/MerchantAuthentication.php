<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization');

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized. Missing or invalid Authorization header.'], Response::HTTP_UNAUTHORIZED);
        }

        $token = substr($header, 7);
        $merchant = null;

        // Security Boundary: Test tokens are strictly restricted to the testing environment
        if (app()->environment('testing') && str_starts_with($token, 'test-token-')) {
            $merchantId = str_replace('test-token-', '', $token);
            $merchant = Merchant::find($merchantId);
        } else {
            // Production path: Hash the token using SHA-256 and look up by api_key_hash
            $tokenHash = hash('sha256', $token);
            $merchant = Merchant::where('api_key_hash', $tokenHash)->first();
        }

        if (!$merchant || $merchant->status !== 'active') {
            return response()->json(['message' => 'Unauthorized. Invalid or inactive merchant.'], Response::HTTP_UNAUTHORIZED);
        }

        // Attach authenticated merchant to request attributes and user resolver
        $request->attributes->set('merchant', $merchant);
        $request->setUserResolver(fn () => $merchant);

        return $next($request);
    }
}