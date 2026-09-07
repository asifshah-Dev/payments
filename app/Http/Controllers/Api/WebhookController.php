<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\WebhookProcessorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use InvalidArgumentException;

class WebhookController extends Controller
{
    public function __construct(
        protected WebhookProcessorService $webhookProcessor
    ) {}

    public function handle(Request $request, string $processor): JsonResponse
    {
        // 1. Extract signature header (e.g., Stripe-Signature or X-Signature)
        $signature = $request->header('Stripe-Signature') 
            ?? $request->header('X-Signature') 
            ?? '';

        // 2. Extract standard event fields from incoming JSON payload
        $payload = $request->all();
        $eventId = $payload['id'] ?? $payload['event_id'] ?? null;
        $eventType = $payload['type'] ?? $payload['event_type'] ?? null;

        if (!$eventId) {
            return response()->json([
                'error' => 'Event ID missing from webhook payload.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$eventType) {
            return response()->json([
                'error' => 'Event type missing from webhook payload.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            // 3. Delegate execution directly to the core WebhookProcessorService
            $this->webhookProcessor->handle(
                processor: $processor,
                eventId: $eventId,
                eventType: $eventType,
                payload: $payload,
                signature: $signature
            );

            return response()->json(['status' => 'success'], Response::HTTP_OK);

        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], Response::HTTP_UNAUTHORIZED);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}