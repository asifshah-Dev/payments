<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\Merchant;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\LedgerTransaction;
use App\Services\LedgerPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RefundLedgerPostingTest extends TestCase
{
    use RefreshDatabase;

    private function createScenario(int $paymentAmount = 10000, int $feeAmount = 200): array
    {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        $clearing = LedgerAccount::create([
            'name' => 'Gateway Clearing',
            'type' => 'asset',
            'currency' => 'USD',
            'merchant_id' => null,
            'status' => 'active',
        ]);
        $payable = LedgerAccount::create([
            'name' => 'Merchant Payable',
            'type' => 'liability',
            'currency' => 'USD',
            'merchant_id' => $merchant->id,
            'status' => 'active',
        ]);
        $feeRevenue = LedgerAccount::create([
            'name' => 'Fee Revenue',
            'type' => 'revenue',
            'currency' => 'USD',
            'merchant_id' => null,
            'status' => 'active',
        ]);
        $intent = PaymentIntent::factory()->create([
            'merchant_id' => $merchant->id,
            'amount' => $paymentAmount,
            'currency' => 'USD',
            'status' => 'succeeded',
        ]);
        $attempt = PaymentAttempt::factory()->create([
            'payment_intent_id' => $intent->id,
            'amount' => $paymentAmount,
            'currency' => 'USD',
            'status' => 'succeeded',
            'fee_amount' => $feeAmount,
        ]);

        return [$merchant, $intent, $attempt, $clearing, $payable, $feeRevenue];
    }

    private function createRefund(PaymentIntent $intent, Merchant $merchant, int $amount, string $key): Refund
    {
        return Refund::create([
            'payment_intent_id' => $intent->id,
            'merchant_id' => $merchant->id,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'succeeded',
            'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
        ]);
    }

    public function test_successful_refund_creates_balanced_ledger_transaction(): void
    {
        [$merchant, $intent, $attempt, $clearing, $payable, $feeRevenue] = $this->createScenario();
        $refund = $this->createRefund($intent, $merchant, 10000, 'refund-full');

        $transaction = app(LedgerPostingService::class)->postRefund($refund);

        $this->assertSame('refund', $transaction->type);
        $this->assertSame(10000, $transaction->amount);
        $this->assertCount(3, $transaction->entries);
        $this->assertSame(9800, $transaction->entries->where('ledger_account_id', $payable->id)->first()->amount);
        $this->assertSame(200, $transaction->entries->where('ledger_account_id', $feeRevenue->id)->first()->amount);
        $this->assertSame(10000, $transaction->entries->where('ledger_account_id', $clearing->id)->first()->amount);
        $this->assertSame(
            $transaction->entries->where('type', 'debit')->sum('amount'),
            $transaction->entries->where('type', 'credit')->sum('amount')
        );
    }

    public function test_failed_refund_creates_no_financial_posting(): void
    {
        [$merchant, $intent] = $this->createScenario();
        $refund = $this->createRefund($intent, $merchant, 1000, 'refund-failed');
        $refund->update(['status' => 'failed']);

        $this->expectException(\RuntimeException::class);
        try {
            app(LedgerPostingService::class)->postRefund($refund);
        } finally {
            $this->assertSame(0, LedgerTransaction::where('type', 'refund')->count());
        }
    }

    public function test_duplicate_refund_posting_does_not_create_another_transaction(): void
    {
        [$merchant, $intent] = $this->createScenario();
        $refund = $this->createRefund($intent, $merchant, 1000, 'refund-duplicate');
        $service = app(LedgerPostingService::class);

        $first = $service->postRefund($refund);
        $second = $service->postRefund($refund);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LedgerTransaction::where('type', 'refund')->count());
    }

    public function test_partial_refund_posts_only_the_refunded_amount(): void
    {
        [$merchant, $intent, , $clearing] = $this->createScenario();
        $refund = $this->createRefund($intent, $merchant, 2500, 'refund-partial');

        $transaction = app(LedgerPostingService::class)->postRefund($refund);

        $this->assertSame(2500, $transaction->amount);
        $this->assertSame(2500, $transaction->entries->where('ledger_account_id', $clearing->id)->first()->amount);
    }

    public function test_multiple_refunds_post_independently(): void
    {
        [$merchant, $intent] = $this->createScenario();
        $first = $this->createRefund($intent, $merchant, 2500, 'refund-one');
        $second = $this->createRefund($intent, $merchant, 3500, 'refund-two');
        $service = app(LedgerPostingService::class);

        $service->postRefund($first);
        $service->postRefund($second);

        $this->assertSame(2, LedgerTransaction::where('type', 'refund')->count());
    }

    public function test_refund_after_payout_debits_merchant_payable_into_negative_balance(): void
    {
        [$merchant, $intent, , , $payable] = $this->createScenario();
        $refund = $this->createRefund($intent, $merchant, 10000, 'refund-after-payout');

        $transaction = app(LedgerPostingService::class)->postRefund($refund);

        $payableMovement = $transaction->entries->where('ledger_account_id', $payable->id)->first();
        $this->assertSame('debit', $payableMovement->type);
        $this->assertSame(9800, $payableMovement->amount);
    }

    public function test_refund_before_payout_creates_the_correct_payable_debit(): void
    {
        [$merchant, $intent, , , $payable] = $this->createScenario();
        $refund = $this->createRefund($intent, $merchant, 4000, 'refund-before-payout');

        $transaction = app(LedgerPostingService::class)->postRefund($refund);

        $payableMovement = $transaction->entries->where('ledger_account_id', $payable->id)->first();
        $this->assertSame(3920, $payableMovement->amount);
    }

    public function test_refund_cannot_exceed_original_payment_amount(): void
    {
        [$merchant, $intent] = $this->createScenario();
        $refund = $this->createRefund($intent, $merchant, 10001, 'refund-too-large');

        $this->expectException(InvalidArgumentException::class);
        app(LedgerPostingService::class)->postRefund($refund);
    }

    public function test_same_refund_cannot_be_posted_to_the_ledger_twice(): void
    {
        [$merchant, $intent] = $this->createScenario();
        $refund = $this->createRefund($intent, $merchant, 1000, 'refund-once');
        $service = app(LedgerPostingService::class);

        $service->postRefund($refund);
        $service->postRefund($refund);

        $this->assertSame(1, LedgerTransaction::where('source_id', $refund->id)->count());
    }
}
