<?php
// app/Http/Middleware/AuthenticateMerchant.php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMerchant
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization');

        if (! $header || ! str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized();
        }

        $rawKey = trim(substr($header, 7));

        if ($rawKey === '') {
            return $this->unauthorized();
        }

        $merchant = Merchant::findByApiKey($rawKey);

        if (! $merchant) {
            return $this->unauthorized();
        }

        if ($merchant->status !== 'active') {
            return $this->unauthorized('Merchant is not active.');
        }

        // Bind the merchant for downstream use. Controllers get it via
        // $request->user() OR $request->attributes->get('merchant').
        $request->setUserResolver(fn () => $merchant);
        $request->attributes->set('merchant', $merchant);

        return $next($request);
    }

    private function unauthorized(string $message = 'Unauthenticated.'): Response
    {
        return response()->json([
            'message' => $message,
        ], 401);
    }
}