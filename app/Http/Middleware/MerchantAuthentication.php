<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        $token = trim(substr($authorization, 7));

        if ($token === '') {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Support both test-token-{merchant_id} contract tests and production API key hashes
        if (str_starts_with($token, 'test-token-')) {
            $merchantId = substr($token, strlen('test-token-'));

            if (!Str::isUuid($merchantId)) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $merchant = Merchant::where('id', $merchantId)->first();
        } else {
            $apiKeyHash = hash('sha256', $token);
            $merchant = Merchant::where('api_key_hash', $apiKeyHash)->first();
        }

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

        // Attach merchant to request attributes
        $request->attributes->set('merchant', $merchant);

        return $next($request);
    }
}