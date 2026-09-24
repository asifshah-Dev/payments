<?php

namespace Tests\Feature\Concurrency;

use App\Models\Merchant;
use App\Models\PaymentIntent;
use App\Models\Refund;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('concurrency')]
class RefundCeilingConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    public function test_two_simultaneous_service_calls_cannot_over_refund(): void
    {
        // ---- Setup: one merchant, one succeeded payment of 10000 ----
        $merchant = Merchant::factory()->create();

        $payment = PaymentIntent::factory()->create([
            'merchant_id'     => $merchant->id,
            'amount'          => 10000,
            'currency'        => 'PKR',
            'status'          => 'succeeded',
            'idempotency_key' => (string) Str::uuid(),
            'request_hash'    => hash('sha256', 'seed'),
        ]);

        // ---- Two refunds, different idempotency keys, 7000 each ----
        // Individually fine (< 10000). Together over the ceiling.
        // Only real concurrency can prove whether the ceiling holds.
        $scriptPath = dirname(__DIR__, 2) . '/scripts/concurrent_refund.php';

        $requests = [
            ['amount' => 7000, 'key' => (string) Str::uuid()],
            ['amount' => 7000, 'key' => (string) Str::uuid()],
        ];

        // ---- Launch both PHP processes at once ----
        $processes = collect($requests)->map(function ($req) use ($scriptPath, $merchant, $payment) {
            $process = new Process([
                'php', $scriptPath,
                $merchant->id,
                $payment->id,
                (string) $req['amount'],
                $req['key'],
            ]);
            $process->setTimeout(30);
            $process->start();
            return $process;
        });

        // ---- Wait for both to finish and collect their output ----
        $outputs = $processes->map(function (Process $p) {
            $p->wait();
            return trim($p->getOutput());
        })->all();

        // ---- The invariant: total refunded must never exceed the payment ----
        $totalRefunded = (int) Refund::query()
            ->where('payment_intent_id', $payment->id)
            ->whereIn('status', ['pending', 'succeeded'])
            ->sum('amount');

        $this->assertLessThanOrEqual(
            $payment->amount,
            $totalRefunded,
            sprintf(
                "OVER-REFUND: total refunded %d exceeds payment amount %d.\nProcess outputs:\n%s",
                $totalRefunded,
                $payment->amount,
                implode("\n", $outputs)
            )
        );

        // ---- And exactly one of the two should have succeeded ----
        $okCount = collect($outputs)->filter(fn ($o) => str_starts_with($o, 'OK:'))->count();

        $this->assertSame(
            1,
            $okCount,
            "Expected exactly one OK. Got:\n" . implode("\n", $outputs)
        );
    }
    public function test_simultaneous_refunds_with_same_key_create_one_refund(): void
{
    // ---- Setup: one merchant, one succeeded payment of 10000 ----
    $merchant = Merchant::factory()->create();

    $payment = PaymentIntent::factory()->create([
        'merchant_id'     => $merchant->id,
        'amount'          => 10000,
        'currency'        => 'PKR',
        'status'          => 'succeeded',
        'idempotency_key' => (string) Str::uuid(),
        'request_hash'    => hash('sha256', 'seed'),
    ]);

    // ---- Two processes, SAME idempotency key, SAME amount ----
    // Only one refund should ever exist. One process creates it (201),
    // the other observes it as a replay (200). Neither can fail.
    $scriptPath = dirname(__DIR__, 2) . '/scripts/concurrent_refund.php';
    $sharedKey  = (string) Str::uuid();

    $processes = collect([1, 2])->map(function () use ($scriptPath, $merchant, $payment, $sharedKey) {
        $process = new Process([
            'php', $scriptPath,
            $merchant->id,
            $payment->id,
            '3000',
            $sharedKey,
        ]);
        $process->setTimeout(30);
        $process->start();
        return $process;
    });

    $outputs = $processes->map(function (Process $p) {
        $p->wait();
        return trim($p->getOutput());
    })->all();

    // ---- Assertion 1: exactly one refund row exists ----
    $refundCount = Refund::query()
        ->where('merchant_id', $merchant->id)
        ->where('idempotency_key', $sharedKey)
        ->count();

    $this->assertSame(
        1,
        $refundCount,
        "Expected exactly one refund for the shared key. Got {$refundCount}.\nProcess outputs:\n" . implode("\n", $outputs)
    );

    // ---- Assertion 2: both processes reported success ----
    // One is OK:201 (created), the other is OK:200 (replay).
    // Neither should error or be rejected.
    $okCount = collect($outputs)->filter(fn ($o) => str_starts_with($o, 'OK:'))->count();

    $this->assertSame(
        2,
        $okCount,
        "Expected both processes to succeed (201 + 200). Got:\n" . implode("\n", $outputs)
    );

    // ---- Assertion 3: exactly one 201 and one 200 ----
    $createdCount = collect($outputs)->filter(fn ($o) => $o === 'OK:201')->count();
    $replayCount  = collect($outputs)->filter(fn ($o) => $o === 'OK:200')->count();

    $this->assertSame(1, $createdCount, "Expected exactly one 201. Got:\n" . implode("\n", $outputs));
    $this->assertSame(1, $replayCount,  "Expected exactly one 200. Got:\n" . implode("\n", $outputs));
}
}