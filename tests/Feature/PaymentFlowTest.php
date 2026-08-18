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

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            ProductSeeder::class,
            PaymentGatewaySeeder::class,
        ]);
        $this->user = User::factory()->create();
    }

    public function test_100_products_are_seeded(): void
    {
        $this->assertDatabaseCount('products', 100);
    }

    public function test_user_can_checkout_using_mock_gateway_strategy_by_uuid(): void
    {
        $product = Product::first();
        $mockGateway = PaymentGateway::where('slug', 'mock')->first();
        $initialStock = $product->quantity;

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/checkout', [
                'payment_gateway_id' => $mockGateway->id,
                'idempotency_key' => 'IK-TEST-001',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 2,
                    ],
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.gateway_id', $mockGateway->id)
            ->assertJsonPath('data.status', 'processing');

        $paymentId = $response->json('data.payment_id');
        $payment = Payment::find($paymentId);

        $this->assertNotNull($payment);
        $this->assertEquals(PaymentStatus::PROCESSING, $payment->status);

        $order = Order::find($payment->order_id);
        $this->assertEquals(OrderStatus::PENDING, $order->status);

        // Simulate Callback completion using gateway UUID
        $callbackResponse = $this->getJson("/api/payments/callback/{$mockGateway->id}?payment_reference={$payment->payment_reference}&status=success");

        $callbackResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        // Verify Payment & Order status updated to COMPLETED
        $payment->refresh();
        $order->refresh();
        $product->refresh();

        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);
        $this->assertEquals(OrderStatus::COMPLETED, $order->status);
        $this->assertEquals($initialStock - 2, $product->quantity);
    }

    public function test_checkout_idempotency_prevents_duplicate_orders(): void
    {
        $product = Product::first();
        $mockGateway = PaymentGateway::where('slug', 'mock')->first();

        $payload = [
            'payment_gateway_id' => $mockGateway->id,
            'idempotency_key' => 'IK-DUPLICATE-KEY',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ];

        // First Request
        $res1 = $this->actingAs($this->user, 'sanctum')->postJson('/api/checkout', $payload);
        $res1->assertStatus(201)->assertJsonPath('data.is_idempotent', false);

        // Second Request (Identical Idempotency Key)
        $res2 = $this->actingAs($this->user, 'sanctum')->postJson('/api/checkout', $payload);
        $res2->assertStatus(200)->assertJsonPath('data.is_idempotent', true);

        // Verify only 1 order and payment were created in the database
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_checkout_fails_with_invalid_gateway_uuid(): void
    {
        $product = Product::first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/checkout', [
                'payment_gateway_id' => Str::uuid()->toString(),
                'idempotency_key' => 'IK-INVALID-KEY',
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_gateway_id']);
    }

    public function test_can_fetch_enabled_payment_gateways(): void
    {
        $response = $this->getJson('/api/payment-gateways');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(3, 'data');
    }

    public function test_user_can_fetch_purchased_products(): void
    {
        $product = Product::first();
        $mockGateway = PaymentGateway::where('slug', 'mock')->first();

        // Perform Checkout
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $mockGateway->id,
            'idempotency_key' => 'IK-PURCHASED-001',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $payment = Payment::find($res->json('data.payment_id'));

        // Confirm Payment via Callback
        $this->getJson("/api/payments/callback/{$mockGateway->id}?payment_reference={$payment->payment_reference}&status=success");

        // Fetch Purchased Products
        $purchasedResponse = $this->actingAs($this->user, 'sanctum')->getJson('/api/purchased-products');

        $purchasedResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.data.0.id', $product->id);
    }
}
