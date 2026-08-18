<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\PaymentGatewaySeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user1;
    protected User $user2;
    protected PaymentGateway $mockGateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            ProductSeeder::class,
            PaymentGatewaySeeder::class,
        ]);
        $this->user1 = User::factory()->create();
        $this->user2 = User::factory()->create();
        $this->mockGateway = PaymentGateway::where('slug', 'mock')->first();
    }

    /* -------------------------------------------------------------------------- */
    /* 1. IDEMPOTENCY TESTS                                                       */
    /* -------------------------------------------------------------------------- */

    public function test_same_checkout_request_sent_twice_returns_identical_payment_without_duplicate_order(): void
    {
        $product = Product::first();
        $payload = [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'IDEM-REPEAT-001',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ];

        // First Request
        $response1 = $this->actingAs($this->user1, 'sanctum')->postJson('/api/checkout', $payload);
        $response1->assertStatus(201)->assertJsonPath('data.is_idempotent', false);

        // Second Request (Identical Idempotency Key)
        $response2 = $this->actingAs($this->user1, 'sanctum')->postJson('/api/checkout', $payload);
        $response2->assertStatus(200)->assertJsonPath('data.is_idempotent', true);

        // Assert 1 Order, 1 Payment in DB
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_same_idempotency_key_from_different_users_creates_separate_operations(): void
    {
        $product = Product::first();
        $key = 'SHARED-KEY-123';

        $payload = [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => $key,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        // User 1 Checkout
        $res1 = $this->actingAs($this->user1, 'sanctum')->postJson('/api/checkout', $payload);
        $res1->assertStatus(201);

        // User 2 Checkout (Same Key)
        $res2 = $this->actingAs($this->user2, 'sanctum')->postJson('/api/checkout', $payload);
        $res2->assertStatus(201);

        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseCount('payments', 2);
    }

    /* -------------------------------------------------------------------------- */
    /* 2. INVENTORY RESERVATION & STOCK TESTS                                     */
    /* -------------------------------------------------------------------------- */

    public function test_checkout_reserves_quantity_without_reducing_physical_quantity_immediately(): void
    {
        $product = Product::create([
            'name' => 'Widget A',
            'price' => 100.00,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->user1, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'IDEM-RESERVE-01',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertStatus(201);

        $product->refresh();
        $this->assertEquals(10, $product->quantity); // Physical stock remains 10
        $this->assertEquals(3, $product->reserved_quantity); // Reserved stock is 3
        $this->assertEquals(7, $product->available_quantity); // Available is 10 - 3 = 7
    }

    public function test_checkout_fails_when_requested_quantity_exceeds_available_quantity(): void
    {
        $product = Product::create([
            'name' => 'Limited Stock Item',
            'price' => 50.00,
            'quantity' => 5,
            'reserved_quantity' => 4, // 1 available
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user1, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'EXCEED-STOCK-01',
            'items' => [['product_id' => $product->id, 'quantity' => 2]], // Requesting 2 when only 1 is available
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'error');

        $product->refresh();
        $this->assertEquals(4, $product->reserved_quantity); // Reserved quantity unadjusted
    }

    /* -------------------------------------------------------------------------- */
    /* 3. PAYMENT SUCCESS TESTS                                                   */
    /* -------------------------------------------------------------------------- */

    public function test_successful_payment_converts_reservation_into_sold_stock(): void
    {
        $product = Product::create([
            'name' => 'Widget B',
            'price' => 200.00,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'is_active' => true,
        ]);

        // 1. Checkout (Reserves 3)
        $checkoutRes = $this->actingAs($this->user1, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'IDEM-SUCCESS-01',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]);

        $paymentId = $checkoutRes->json('data.payment_id');
        $payment = Payment::find($paymentId);

        $product->refresh();
        $this->assertEquals(10, $product->quantity);
        $this->assertEquals(3, $product->reserved_quantity);

        // 2. Execute Callback (Payment Success)
        $this->getJson("/api/payments/callback/{$this->mockGateway->id}?payment_reference={$payment->payment_reference}&status=success")
            ->assertStatus(200);

        $product->refresh();
        $payment->refresh();

        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);
        $this->assertEquals(7, $product->quantity); // Physical stock decreased: 10 - 3 = 7
        $this->assertEquals(0, $product->reserved_quantity); // Reserved stock decreased: 3 - 3 = 0
        $this->assertEquals(7, $product->available_quantity); // Available stock remains 7
    }

    public function test_duplicate_callbacks_and_webhooks_do_not_deduct_stock_twice(): void
    {
        $product = Product::create([
            'name' => 'Widget C',
            'price' => 150.00,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'is_active' => true,
        ]);

        $checkoutRes = $this->actingAs($this->user1, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'DUP-CB-01',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ]);

        $paymentId = $checkoutRes->json('data.payment_id');
        $payment = Payment::find($paymentId);

        // First Callback
        $this->getJson("/api/payments/callback/{$this->mockGateway->id}?payment_reference={$payment->payment_reference}&status=success");

        // Second Callback (Duplicate)
        $this->getJson("/api/payments/callback/{$this->mockGateway->id}?payment_reference={$payment->payment_reference}&status=success");

        // Webhook (Duplicate)
        $this->postJson("/api/payments/webhook/{$this->mockGateway->id}", [
            'payment_id' => $paymentId,
            'status' => 'completed',
        ]);

        $product->refresh();
        $this->assertEquals(8, $product->quantity); // Stock deducted ONLY ONCE (10 - 2 = 8)
        $this->assertEquals(0, $product->reserved_quantity);
    }

    /* -------------------------------------------------------------------------- */
    /* 4. PAYMENT FAILURE TESTS                                                   */
    /* -------------------------------------------------------------------------- */

    public function test_failed_payment_releases_reserved_quantity_without_reducing_physical_stock(): void
    {
        $product = Product::create([
            'name' => 'Widget D',
            'price' => 80.00,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'is_active' => true,
        ]);

        // Checkout (Reserves 4)
        $checkoutRes = $this->actingAs($this->user1, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'FAIL-RESERVE-01',
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ]);

        $paymentId = $checkoutRes->json('data.payment_id');
        $payment = Payment::find($paymentId);

        $product->refresh();
        $this->assertEquals(10, $product->quantity);
        $this->assertEquals(4, $product->reserved_quantity);

        // Execute Callback (Payment Failure)
        $this->getJson("/api/payments/callback/{$this->mockGateway->id}?payment_reference={$payment->payment_reference}&status=failed")
            ->assertStatus(400);

        $product->refresh();
        $payment->refresh();
        $order = Order::find($payment->order_id);

        $this->assertEquals(PaymentStatus::FAILED, $payment->status);
        $this->assertEquals(OrderStatus::CANCELLED, $order->status);
        $this->assertEquals(10, $product->quantity); // Physical stock NOT decreased (10)
        $this->assertEquals(0, $product->reserved_quantity); // Reserved stock released back to 0
        $this->assertEquals(10, $product->available_quantity);
    }

    public function test_duplicate_failure_webhook_does_not_release_reservation_twice(): void
    {
        $product = Product::create([
            'name' => 'Widget E',
            'price' => 90.00,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'is_active' => true,
        ]);

        $checkoutRes = $this->actingAs($this->user1, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'DUP-FAIL-01',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]);

        $paymentId = $checkoutRes->json('data.payment_id');
        $payment = Payment::find($paymentId);

        // Failure Callback 1
        $this->getJson("/api/payments/callback/{$this->mockGateway->id}?payment_reference={$payment->payment_reference}&status=failed");

        // Failure Webhook 2 (Duplicate)
        $this->postJson("/api/payments/webhook/{$this->mockGateway->id}", [
            'payment_id' => $paymentId,
            'status' => 'failed',
        ]);

        $product->refresh();
        $this->assertEquals(10, $product->quantity);
        $this->assertEquals(0, $product->reserved_quantity); // Reservation released to 0, not negative
    }

    public function test_duplicate_product_ids_in_same_checkout_are_aggregated_safely(): void
    {
        $product = Product::create([
            'name' => 'Widget F',
            'price' => 50.00,
            'quantity' => 10,
            'reserved_quantity' => 0,
            'is_active' => true,
        ]);

        // Checkout with duplicate product ID (2 + 3 = 5 total)
        $res = $this->actingAs($this->user1, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'DUP-PROD-ID-01',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ]);

        $res->assertStatus(201);

        $product->refresh();
        $this->assertEquals(10, $product->quantity);
        $this->assertEquals(5, $product->reserved_quantity); // Aggregated to 5 reserved
    }
}
