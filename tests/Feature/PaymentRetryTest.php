<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\PaymentGatewaySeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentRetryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected PaymentGateway $mockGateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            ProductSeeder::class,
            PaymentGatewaySeeder::class,
        ]);
        $this->user = User::factory()->create();
        $this->mockGateway = PaymentGateway::where('slug', 'mock')->first();
    }

    public function test_concurrent_retry_requests_guarantee_single_active_attempt_and_prevent_double_processing(): void
    {
        $product = Product::first();

        // 1. Checkout
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'RETRY-CONCURRENCY-001',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $paymentId = $res->json('data.payment_id');

        // Manually mark an active processing attempt (unfinished)
        PaymentAttempt::create([
            'payment_id' => $paymentId,
            'attempt_number' => 1,
            'status' => PaymentAttemptStatus::PROCESSING,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);

        // Second Worker tries to retry while worker 1 is active
        $retryResult = $paymentService->retryPayment($paymentId);

        $this->assertFalse($retryResult['is_idempotent']);
        $this->assertTrue($retryResult['already_processing']);
        $this->assertStringContainsString('already being processed', $retryResult['message']);

        // Assert no duplicate attempt created
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_attempt_older_than_5_minutes_that_is_still_unfinished_blocks_second_worker_from_double_charging(): void
    {
        $product = Product::first();

        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'OLD-ATTEMPT-KEY-01',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $paymentId = $res->json('data.payment_id');

        // Active attempt started 10 minutes ago, but NOT finished yet (finished_at IS NULL)
        PaymentAttempt::create([
            'payment_id' => $paymentId,
            'attempt_number' => 1,
            'status' => PaymentAttemptStatus::PROCESSING,
            'started_at' => now()->subMinutes(10),
            'finished_at' => null,
        ]);

        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);
        $retryResult = $paymentService->retryPayment($paymentId);

        // Verify second worker is blocked and does NOT create a second attempt
        $this->assertFalse($retryResult['is_idempotent']);
        $this->assertTrue($retryResult['already_processing']);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_late_gateway_response_does_not_regress_completed_payment_state_when_webhook_arrived_first(): void
    {
        $product = Product::create([
            'name' => 'Race Item',
            'price' => 100.00,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'is_active' => true,
        ]);

        // 1. Checkout (Reserves 2)
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'RACE-WEBHOOK-FIRST-01',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);

        $paymentId = $res->json('data.payment_id');
        $payment = Payment::find($paymentId);

        // 2. Webhook arrives FIRST while processPayment was running, marks payment COMPLETED
        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);
        $paymentService->finalizePaymentStatus($paymentId, PaymentStatus::COMPLETED, ['transaction_id' => 'TXN-WEBHOOK-FAST']);

        $payment->refresh();
        $order = Order::find($payment->order_id);
        $product->refresh();

        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);
        $this->assertEquals(OrderStatus::COMPLETED, $order->status);
        $this->assertEquals(8, $product->quantity); // Stock deducted once: 10 - 2 = 8
        $this->assertEquals(0, $product->reserved_quantity);

        // 3. Late Gateway response returns SUCCESS
        $attemptResult = $paymentService->executePaymentAttempt($payment);

        $payment->refresh();
        $order->refresh();
        $product->refresh();

        // Verify state remains COMPLETED (no regression) and stock is NOT deducted twice
        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);
        $this->assertEquals(OrderStatus::COMPLETED, $order->status);
        $this->assertEquals(8, $product->quantity);
        $this->assertEquals(0, $product->reserved_quantity);
    }

    public function test_invalid_status_regressions_are_blocked_by_state_machine(): void
    {
        $this->assertFalse(PaymentStatus::COMPLETED->canTransitionTo(PaymentStatus::PROCESSING));
        $this->assertFalse(PaymentStatus::COMPLETED->canTransitionTo(PaymentStatus::FAILED));
        $this->assertFalse(PaymentStatus::COMPLETED->canTransitionTo(PaymentStatus::CANCELLED));
        $this->assertFalse(PaymentStatus::CANCELLED->canTransitionTo(PaymentStatus::PROCESSING));
    }

    public function test_completed_payment_cannot_be_retried(): void
    {
        $product = Product::first();
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'NO-RETRY-COMPLETED-01',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $paymentId = $res->json('data.payment_id');
        $payment = Payment::find($paymentId);

        // Complete Payment
        $this->getJson("/api/payments/callback/{$this->mockGateway->id}?payment_reference={$payment->payment_reference}&status=success");

        $payment->refresh();
        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);

        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);
        $retryResult = $paymentService->retryPayment($paymentId);

        $this->assertFalse($retryResult['success'] ?? true);
        $this->assertStringContainsString('completed', strtolower($retryResult['message']));
    }

    public function test_permanent_failure_marks_payment_failed_and_releases_reserved_inventory_once(): void
    {
        $product = Product::first();
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'PERM-FAIL-002',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]);

        $paymentId = $res->json('data.payment_id');

        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);
        $paymentService->markPaymentAsPermanentlyFailed($paymentId, 'Max retry limit reached.');

        $payment = Payment::find($paymentId);
        $order = Order::find($payment->order_id);
        $product->refresh();

        $this->assertEquals(PaymentStatus::FAILED, $payment->status);
        $this->assertEquals(OrderStatus::CANCELLED, $order->status);
        $this->assertEquals(0, $product->reserved_quantity);
    }
}
