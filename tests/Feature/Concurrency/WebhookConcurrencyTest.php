<?php

namespace Tests\Feature\Concurrency;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\RefundAttempt;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('concurrency')]
class WebhookConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    public function test_two_simultaneous_identical_webhooks_create_one_event(): void
    {
        // ---- Setup: one merchant, one refund attempt in 'pending' ----
        $merchant = Merchant::factory()->create();

        $intent = PaymentIntent::create([
            'merchant_id'     => $merchant->id,
            'amount'          => 10000,
            'currency'        => 'USD',
            'status'          => 'succeeded',
            'idempotency_key' => (string) Str::uuid(),
            'request_hash'    => hash('sha256', 'seed'),
        ]);

        $refund = Refund::create([
            'payment_intent_id' => $intent->id,
            'merchant_id'       => $merchant->id,
            'amount'            => 3000,
            'currency'          => 'USD',
            'status'            => 'pending',
            'idempotency_key'   => (string) Str::uuid(),
            'request_hash'      => hash('sha256', 'seed'),
        ]);

        $attempt = RefundAttempt::create([
            'refund_id'              => $refund->id,
            'processor'              => 'stripe',
            'status'                 => 'pending',
            'attempt_number'         => 1,
            'processor_reference_id' => 're_' . fake()->unique()->numerify('######'),
        ]);

        // ---- Build the exact raw body + real HMAC signature ----
        $body = json_encode([
            'id'   => 'evt_concurrent_dup',
            'type' => 'refund.succeeded',
            'data' => ['object' => ['id' => $attempt->processor_reference_id]],
        ]);

        $secret    = config('webhooks.secrets.stripe');
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
        $header    = "t={$timestamp},v1={$signature}";

        // ---- Write body + header to a temp file the helper script reads ----
        $payloadFile = tempnam(sys_get_temp_dir(), 'wh_');
        file_put_contents($payloadFile, json_encode([
            'body'   => $body,
            'header' => $header,
        ]));

        $scriptPath = dirname(__DIR__, 2) . '/scripts/concurrent_webhook.php';

        // ---- Launch two processes at once ----
        $processes = collect([1, 2])->map(function () use ($scriptPath, $payloadFile) {
            $p = new Process(['php', $scriptPath, $payloadFile]);
            $p->setTimeout(30);
            $p->start();
            return $p;
        });

        $outputs = $processes->map(function (Process $p) {
            $p->wait();
            return trim($p->getOutput());
        })->all();

        @unlink($payloadFile);

        // ---- Exactly one event row ----
        $this->assertSame(
            1,
            PaymentWebhookEvent::where('event_id', 'evt_concurrent_dup')->count(),
            "Expected exactly one event row. Process outputs:\n" . implode("\n", $outputs),
        );

        // ---- And exactly one state transition ----
        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame('succeeded', $refund->fresh()->status);
    }
}