<?php

/**
 * Standalone PHP script that boots Laravel and posts one signed webhook
 * through the HTTP kernel, without going through the PHP test client.
 * Called by the concurrency test as a separate OS process, so multiple
 * invocations run in true parallel.
 *
 * Usage:
 *   php concurrent_webhook.php <payload_file>
 *
 * The payload file is JSON containing:
 *   {
 *     "body":   "<raw request body string>",
 *     "header": "<Stripe-Signature header value>"
 *   }
 */

require __DIR__ . '/../../vendor/autoload.php';

use Illuminate\Http\Request;

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$payloadFile = $argv[1] ?? null;
if (! $payloadFile || ! file_exists($payloadFile)) {
    fwrite(STDERR, "Payload file missing.\n");
    exit(1);
}

$data = json_decode(file_get_contents($payloadFile), true);
if (! isset($data['body'], $data['header'])) {
    fwrite(STDERR, "Payload file is malformed.\n");
    exit(1);
}

$request = Request::create(
    '/api/v1/webhooks/stripe',
    'POST',
    [],
    [],
    [],
    [
        'CONTENT_TYPE'          => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $data['header'],
    ],
    $data['body'],
);

$kernel   = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);

fwrite(STDOUT, 'STATUS:' . $response->getStatusCode() . "\n");