<?php

/**
 * Standalone PHP script that boots Laravel and performs one refund.
 * Called by the concurrency test as a separate OS process, so multiple
 * invocations run in true parallel.
 *
 * Usage:
 *   php concurrent_refund.php <merchant_id> <payment_id> <amount> <idempotency_key>
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$merchantId     = $argv[1] ?? null;
$paymentId      = $argv[2] ?? null;
$amount         = (int) ($argv[3] ?? 0);
$idempotencyKey = $argv[4] ?? null;

if (! $merchantId || ! $paymentId || ! $amount || ! $idempotencyKey) {
    fwrite(STDERR, "Missing arguments.\n");
    exit(1);
}

$merchant = App\Models\Merchant::find($merchantId);
$payment  = App\Models\PaymentIntent::find($paymentId);

if (! $merchant || ! $payment) {
    fwrite(STDERR, "Merchant or payment not found.\n");
    exit(2);
}

try {
    $result = app(App\Services\RefundService::class)->refund(
        merchant:       $merchant,
        payment:        $payment,
        amount:         $amount,
        reason:         null,
        idempotencyKey: $idempotencyKey,
    );

    fwrite(STDOUT, "OK:" . $result['status_code'] . "\n");
    exit(0);
} catch (App\Exceptions\Refund\RefundNotAllowedException $e) {
    fwrite(STDOUT, "REJECTED:" . $e->getMessage() . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, "ERROR:" . $e->getMessage() . "\n");
    exit(3);
}