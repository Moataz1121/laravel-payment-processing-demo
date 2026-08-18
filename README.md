# High-Concurrency & Multi-Gateway Payment Processing Architecture

A production-grade, transactionally consistent, high-concurrency Laravel payment processing engine. Designed with strict database-level idempotency, pessimistic inventory reservation locking, short DB transaction boundaries, and a extensible Strategy/Factory design pattern for payment gateways.

---

## Key Architectural Principles

### 1. Concurrency & Race Condition Safeguards

* **Pessimistic Inventory Locking (`SELECT ... FOR UPDATE`)**:
  When a user initiates checkout, product rows are locked using `lockForUpdate()`. Available stock is validated strictly as:
  $$\text{Available Stock} = \text{quantity} - \text{reserved\_quantity}$$
  If available stock is sufficient, `reserved_quantity` is incremented.

* **Deterministic Lock Ordering (Deadlock Prevention)**:
  To prevent database deadlocks under high-concurrency traffic, products are locked in strict ascending order by ID:
  ```php
  $products = Product::whereIn('id', $productIds)
      ->orderBy('id', 'asc')
      ->lockForUpdate()
      ->get();
  ```

* **Short DB Lock Boundaries**:
  External HTTP API calls to payment gateways (e.g., Moyasar, Stripe) are executed **outside** database transactions. Database locks (~1ms) are held exclusively during local state claims and updates.

* **Database Engine Single Active Attempt Ownership**:
  To prevent multiple background workers from processing the same payment simultaneously, `payment_attempts` enforces a database-level unique constraint on `active_payment_id`. Only one active attempt (`finished_at IS NULL`) can exist per payment at any time.

---

### 2. Double-Layered Idempotency

#### Layer 1: Application-Level Idempotency (`idempotency_key`)
* Supported via a database-level unique index on `payments`:
  ```sql
  UNIQUE(user_id, idempotency_key)
  ```
* Repeating a checkout request with the same `idempotency_key` guarantees that no duplicate orders, payments, or stock reservations are created. The existing payment details are returned with `is_idempotent => true`.

#### Layer 2: Gateway-Level Idempotency (`pay_idem_{payment_id}`)
* Protects customers from double-charging during network timeouts or retries.
* Every gateway request sends a stable, deterministic idempotency key (`pay_idem_{payment_id}`). If a network timeout occurs and the request is retried, the payment provider recognizes the key and returns the original transaction result without charging the customer twice.

---

### 3. Payment State Machine & Regression Protection

Payment state transitions are strictly governed by `App\Enums\PaymentStatus`:

* **Terminal States**: `COMPLETED`, `CANCELLED`, `EXPIRED`, and `REFUNDED` cannot be retried or regressed.
* **Late Response Guard**: If a fast webhook marks a payment `COMPLETED` while a worker HTTP request is still in-flight, the late worker response will record the attempt log but **will NOT regress** the payment status from `COMPLETED` to `PROCESSING` or `FAILED`.

---

## Extending Payment Gateways (Factory & Strategy Pattern)

The system uses a decoupled Strategy and Factory design pattern. Adding a new payment gateway (e.g., PayTabs, Fawry, PayPal) requires zero modifications to core business logic or checkout controllers.

```
                    +--------------------------------+
                    |    PaymentGatewayInterface     |
                    +---------------+----------------+
                                    |
            +-----------------------+-----------------------+
            |                       |                       |
+-----------v-----------+ +---------v-----------+ +---------v-----------+
| MoyasarPaymentGateway | | StripePaymentGateway| |  NewPaymentGateway  |
+-----------------------+ +---------------------+ +---------------------+
```

### Steps to Add a New Payment Gateway Strategy

#### Step 1: Create the Strategy Class
Create a new strategy class in `app/Services/PaymentHandlers/Strategies/` implementing `PaymentGatewayInterface`:

```php
namespace App\Services\PaymentHandlers\Strategies;

use App\Models\Payment;
use App\Services\PaymentHandlers\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Request;

class TapPaymentGateway implements PaymentGatewayInterface
{
    public function processPayment(Payment $payment, ?string $gatewayIdempotencyKey = null): array
    {
        $idempotencyKey = $gatewayIdempotencyKey ?? ("pay_idem_" . $payment->id);

        // 1. Call Gateway API with idempotencyKey
        // 2. Return standardized array
        return [
            'success' => true,
            'payment_id' => $payment->id,
            'transaction_id' => 'TAP-TXN-12345',
            'status' => 'processing',
            'payment_url' => 'https://checkout.tap.company/...',
            'message' => 'Tap checkout session created',
            'retryable' => false,
        ];
    }

    public function callback(Request $request): array
    {
        // Handle browser callback verification
    }

    public function handleWebhook(Request $request): array
    {
        // Handle server-to-server webhook verification
    }

    public function processRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        // Process refund via API
    }
}
```

#### Step 2: Register Strategy in `PaymentGatewayFactory`
Add the new strategy mapping inside `app/Services/PaymentHandlers/PaymentGatewayFactory.php`:

```php
public function create(PaymentGateway|string $gateway): PaymentGatewayInterface
{
    $slug = strtolower($gateway instanceof PaymentGateway ? $gateway->slug : $gateway);

    return match ($slug) {
        'moyasar' => app(MoyasarPaymentGateway::class),
        'stripe' => app(StripePaymentGateway::class),
        'tap' => app(TapPaymentGateway::class), // <-- Registered here
        default => app(MockPaymentGateway::class),
    };
}
```

#### Step 3: Add Gateway Record in Database
Seed or insert a record into the `payment_gateways` table:

```sql
INSERT INTO payment_gateways (id, name, slug, is_enabled, creds)
VALUES ('01a014af-xxxx-xxxx-xxxx-xxxxxxxxxxxx', 'Tap Payments', 'tap', true, '{"secret_key": "sk_test_xxx"}');
```

---

## Inventory Lifecycle

```
[ Checkout Initiated ] 
        |
        v
  reserved_quantity += quantity  (Physical stock unchanged)
        |
        +-----------------------+-----------------------+
        |                                               |
        v                                               v
[ Payment COMPLETED ]                         [ Payment FAILED / CANCELLED ]
        |                                               |
        v                                               v
  quantity -= order_qty                         reserved_quantity -= order_qty
  reserved_quantity -= order_qty                (Physical stock untouched)
  (Converted to Sold Stock)
```

---

## Running Test Suite

Run the full PHPUnit concurrency and integration test suite:

```bash
./vendor/bin/phpunit
```

To run feature concurrency tests specifically:

```bash
./vendor/bin/phpunit --filter=InventoryAndConcurrencyTest
./vendor/bin/phpunit --filter=PaymentRetryTest
./vendor/bin/phpunit --filter=PaymentEventsTest
```
