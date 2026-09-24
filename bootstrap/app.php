<?php

use App\Exceptions\Refund\IdempotencyConflictException;
use App\Exceptions\Refund\RefundNotAllowedException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (
            IdempotencyConflictException $e,
            $request
        ) {
            return response()->json(['message' => $e->getMessage()], 409);
        });

        $exceptions->render(function (
            RefundNotAllowedException $e,
            $request
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
    })
    ->create();