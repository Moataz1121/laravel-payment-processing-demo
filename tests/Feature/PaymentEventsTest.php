<?php

namespace Tests\Feature;

use App\Events\PaymentSuccessEvent;
use App\Listeners\SendPaymentSuccessNotification;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\PaymentGatewaySeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

use Tests\TestCase;

class PaymentEventsTest extends TestCase
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

    public function test_payment_success_dispatches_payment_success_event(): void
    {
        Event::fake([PaymentSuccessEvent::class]);

        $product = Product::first();
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'EVENT-TEST-001',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $paymentId = $res->json('data.payment_id');
        $payment = Payment::find($paymentId);

        // Execute Callback
        $this->getJson("/api/payments/callback/{$this->mockGateway->id}?payment_reference={$payment->payment_reference}&status=success");

        Event::assertDispatched(PaymentSuccessEvent::class, function ($event) use ($paymentId) {
            return $event->payment->id === $paymentId;
        });
    }

    public function test_listener_processed_twice_does_not_create_duplicate_notification_records(): void
    {
        $product = Product::first();
        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/checkout', [
            'payment_gateway_id' => $this->mockGateway->id,
            'idempotency_key' => 'IDEM-NOTIF-001',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $payment = Payment::find($res->json('data.payment_id'));
        $event = new PaymentSuccessEvent($payment);

        $listener = new SendPaymentSuccessNotification();

        // Handle Event First Time
        $listener->handle($event);
        $this->assertDatabaseCount('payment_notifications', 1);

        // Handle Event Second Time (Duplicate Execution)
        $listener->handle($event);
        $this->assertDatabaseCount('payment_notifications', 1); // Remains 1 notification record
    }
}
