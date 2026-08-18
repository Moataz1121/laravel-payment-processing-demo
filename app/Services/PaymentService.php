<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Events\PaymentSuccessEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\PaymentHandlers\Contracts\PaymentGatewayInterface;
use App\Services\PaymentHandlers\PaymentGatewayFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class PaymentService
{
    public function __construct(
        protected PaymentGatewayFactory $gatewayFactory,
        protected PaymentRepositoryInterface $paymentRepository
    ) {}

    /**
     * Initiate checkout: Validates stock, reserves inventory, creates Order & Payment under a short DB transaction.
     * Enforces concurrency safety, aggregated product IDs, and scoped idempotency unique(['user_id', 'idempotency_key']).
     */
    public function initiateCheckout(User $user, array $items, string $gatewayId, string $idempotencyKey): array
    {
        // 1. App-level Idempotency Check
        $existingPayment = $this->paymentRepository->findByIdempotencyKey($user->id, $idempotencyKey);

        if ($existingPayment) {
            return $this->formatIdempotentResponse($existingPayment);
        }

        // 2. Validate Payment Gateway
        $gatewayQuery = PaymentGateway::query();
        if (Str::isUuid($gatewayId)) {
            $gatewayQuery->where('id', $gatewayId);
        } else {
            $gatewayQuery->where('slug', strtolower($gatewayId));
        }

        $gatewayModel = $gatewayQuery->first();

        if (! $gatewayModel || ! $gatewayModel->is_enabled) {
            throw new InvalidArgumentException("Selected payment gateway is invalid or disabled.");
        }

        // 3. Aggregate items by product_id to prevent duplicate product entry corruption
        $aggregatedItems = [];
        foreach ($items as $item) {
            $pId = (int) ($item['product_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);

            if ($pId <= 0 || $qty <= 0) {
                throw new InvalidArgumentException("Product ID and quantity must be positive integers.");
            }

            if (! isset($aggregatedItems[$pId])) {
                $aggregatedItems[$pId] = [
                    'product_id' => $pId,
                    'quantity' => 0,
                ];
            }
            $aggregatedItems[$pId]['quantity'] += $qty;
        }
        $items = array_values($aggregatedItems);

        // Sort items by product_id ascending to guarantee lock acquisition order across transactions and avoid deadlocks
        usort($items, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);

        try {
            // 4. Reserve Inventory & Create Order/Payment inside short DB Transaction
            /** @var Payment $payment */
            $payment = DB::transaction(function () use ($user, $items, $gatewayModel, $idempotencyKey) {
                $productIds = array_column($items, 'product_id');

                // Lock product rows in deterministic ID order with SELECT ... FOR UPDATE
                $products = Product::whereIn('id', $productIds)
                    ->where('is_active', true)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $totalAmount = 0.00;
                $orderItemsData = [];

                foreach ($items as $item) {
                    $productId = $item['product_id'];
                    $requestedQty = (int) $item['quantity'];

                    if (! isset($products[$productId])) {
                        throw new InvalidArgumentException("Product ID {$productId} is not available.");
                    }

                    /** @var Product $product */
                    $product = $products[$productId];
                    $availableQty = $product->quantity - $product->reserved_quantity;

                    if ($availableQty < $requestedQty) {
                        throw new InvalidArgumentException("Insufficient stock for product '{$product->name}'. Available: {$availableQty}, Requested: {$requestedQty}");
                    }

                    $itemTotalPrice = round($product->price * $requestedQty, 2);
                    $totalAmount += $itemTotalPrice;

                    // Reserve inventory by incrementing reserved_quantity
                    $product->increment('reserved_quantity', $requestedQty);

                    $orderItemsData[] = [
                        'product_id' => $product->id,
                        'quantity' => $requestedQty,
                        'unit_price' => $product->price,
                        'total_price' => $itemTotalPrice,
                    ];
                }

                // Create Order
                $order = Order::create([
                    'user_id' => $user->id,
                    'status' => OrderStatus::PENDING,
                    'total_amount' => (float) $totalAmount,
                    'currency' => 'USD',
                ]);

                // Create OrderItems with snapshot unit prices
                foreach ($orderItemsData as $itemData) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $itemData['product_id'],
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'total_price' => $itemData['total_price'],
                    ]);
                }

                $reference = 'PAY-' . strtoupper(Str::random(10));

                return $this->paymentRepository->createPayment([
                    'payment_gateway_id' => $gatewayModel->id,
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'payment_reference' => $reference,
                    'idempotency_key' => $idempotencyKey,
                    'amount' => (float) $totalAmount,
                    'currency' => 'USD',
                    'status' => PaymentStatus::PENDING,
                ]);
            });

        } catch (UniqueConstraintViolationException $e) {
            // DB unique constraint unique(['user_id', 'idempotency_key']) caught concurrent duplicate request
            Log::info("Concurrent duplicate checkout request caught by DB unique constraint for user {$user->id}");
            $existingPayment = $this->paymentRepository->findByIdempotencyKey($user->id, $idempotencyKey);
            if ($existingPayment) {
                return $this->formatIdempotentResponse($existingPayment);
            }
            throw $e;
        }

        // 5. Process Payment Gateway Attempt (Atomic Ownership + Gateway Idempotency)
        return $this->executePaymentAttempt($payment);
    }

    /**
     * Atomically claims attempt ownership and executes payment gateway call OUTSIDE database transactions.
     */
    public function executePaymentAttempt(Payment $payment): array
    {
        // Step A: Short DB Transaction to atomically claim payment attempt ownership
        $claim = DB::transaction(function () use ($payment) {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->first();

            if (! $lockedPayment) {
                return ['allowed' => false, 'reason' => 'Payment record not found.'];
            }

            if (! $lockedPayment->status->canBeRetried()) {
                return [
                    'allowed' => false,
                    'reason' => "Payment is in '{$lockedPayment->status->value}' state and cannot be processed.",
                    'status' => $lockedPayment->status->value,
                    'payment_url' => $lockedPayment->payment_url,
                ];
            }

            // Check if another worker is currently processing an active attempt (finished_at IS NULL)
            $activeAttempt = PaymentAttempt::where('payment_id', $lockedPayment->id)
                ->where('status', PaymentAttemptStatus::PROCESSING)
                ->whereNull('finished_at')
                ->first();

            if ($activeAttempt) {
                return [
                    'allowed' => false,
                    'already_processing' => true,
                    'reason' => 'Payment is already being processed by another worker.',
                    'status' => $lockedPayment->status->value,
                    'payment_url' => $lockedPayment->payment_url,
                ];
            }

            try {
                $nextAttemptNumber = (PaymentAttempt::where('payment_id', $lockedPayment->id)->max('attempt_number') ?? 0) + 1;

                // Create active attempt with active_payment_id to enforce DB-level active attempt uniqueness
                $attempt = PaymentAttempt::create([
                    'payment_id' => $lockedPayment->id,
                    'active_payment_id' => $lockedPayment->id,
                    'attempt_number' => $nextAttemptNumber,
                    'status' => PaymentAttemptStatus::PROCESSING,
                    'started_at' => now(),
                ]);

                if ($lockedPayment->status->canTransitionTo(PaymentStatus::PROCESSING)) {
                    $lockedPayment->update(['status' => PaymentStatus::PROCESSING]);
                }

                return [
                    'allowed' => true,
                    'attempt' => $attempt,
                    'payment' => $lockedPayment->fresh(),
                ];

            } catch (UniqueConstraintViolationException $e) {
                return [
                    'allowed' => false,
                    'already_processing' => true,
                    'reason' => 'Concurrent payment attempt creation collision.',
                    'status' => $lockedPayment->status->value,
                    'payment_url' => $lockedPayment->payment_url,
                ];
            }
        });

        if (! ($claim['allowed'] ?? false)) {
            return [
                'is_idempotent' => false,
                'already_processing' => $claim['already_processing'] ?? false,
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'payment_reference' => $payment->payment_reference,
                'gateway_id' => $payment->payment_gateway_id,
                'status' => $claim['status'] ?? $payment->status->value,
                'payment_url' => $claim['payment_url'] ?? $payment->payment_url,
                'message' => $claim['reason'] ?? 'Payment is already being processed.',
            ];
        }

        /** @var PaymentAttempt $attempt */
        $attempt = $claim['attempt'];
        /** @var Payment $currentPayment */
        $currentPayment = $claim['payment'];

        // Step B: Call Gateway API OUTSIDE DB Transaction using Gateway-level Idempotency Key
        $gatewayIdempotencyKey = "pay_idem_{$currentPayment->id}";
        $strategy = $this->gatewayFactory->create($currentPayment->payment_gateway_id);

        $gatewayResult = null;
        $exception = null;

        try {
            $gatewayResult = $strategy->processPayment($currentPayment, $gatewayIdempotencyKey);
        } catch (\Throwable $e) {
            $exception = $e;
            Log::error("Gateway exception during attempt {$attempt->attempt_number} for payment {$currentPayment->id}: " . $e->getMessage());
        }

        // Step C: Short DB Transaction to record attempt result and update payment status cleanly (Prevent State Regression)
        return DB::transaction(function () use ($currentPayment, $attempt, $gatewayResult, $exception) {
            $lockedPayment = Payment::where('id', $currentPayment->id)->lockForUpdate()->first();

            if ($exception) {
                $attempt->update([
                    'active_payment_id' => null, // Release active attempt DB lock
                    'status' => PaymentAttemptStatus::FAILED,
                    'error_code' => 'NETWORK_TIMEOUT',
                    'error_message' => $exception->getMessage(),
                    'finished_at' => now(),
                ]);

                // Payment state remains in current state (network timeout recovery)
                return [
                    'is_idempotent' => false,
                    'already_processing' => false,
                    'order_id' => $lockedPayment->order_id,
                    'payment_id' => $lockedPayment->id,
                    'payment_reference' => $lockedPayment->payment_reference,
                    'gateway_id' => $lockedPayment->payment_gateway_id,
                    'status' => $lockedPayment->status->value,
                    'payment_url' => $lockedPayment->payment_url,
                    'message' => 'Gateway request timed out; payment state pending reconciliation.',
                ];
            }

            $isSuccess = $gatewayResult['success'] ?? false;
            $isRetryable = $gatewayResult['retryable'] ?? false;

            $attempt->update([
                'active_payment_id' => null, // Release active attempt DB lock
                'status' => $isSuccess ? PaymentAttemptStatus::SUCCEEDED : PaymentAttemptStatus::FAILED,
                'response_payload' => $gatewayResult['data'] ?? $gatewayResult,
                'error_message' => $isSuccess ? null : ($gatewayResult['message'] ?? null),
                'finished_at' => now(),
            ]);

            // STATE REGRESSION GUARD: If payment was already completed by callback/webhook, preserve COMPLETED status!
            if ($lockedPayment->status === PaymentStatus::COMPLETED) {
                return [
                    'is_idempotent' => false,
                    'already_processing' => false,
                    'order_id' => $lockedPayment->order_id,
                    'payment_id' => $lockedPayment->id,
                    'payment_reference' => $lockedPayment->payment_reference,
                    'gateway_id' => $lockedPayment->payment_gateway_id,
                    'status' => PaymentStatus::COMPLETED->value,
                    'payment_url' => $lockedPayment->payment_url,
                    'message' => 'Payment already completed via webhook/callback.',
                ];
            }

            if ($isSuccess) {
                if ($lockedPayment->status->canTransitionTo(PaymentStatus::PROCESSING)) {
                    $lockedPayment->update([
                        'status' => PaymentStatus::PROCESSING,
                        'transaction_id' => $gatewayResult['transaction_id'] ?? $lockedPayment->transaction_id,
                        'payment_url' => $gatewayResult['payment_url'] ?? $lockedPayment->payment_url,
                        'payment_initiated_at' => now(),
                    ]);
                }
            } else {
                $targetStatus = $isRetryable ? PaymentStatus::PROCESSING : PaymentStatus::FAILED;
                if ($lockedPayment->status->canTransitionTo($targetStatus)) {
                    $lockedPayment->update([
                        'status' => $targetStatus,
                        'failure_reason' => $gatewayResult['message'] ?? 'Gateway returned failure',
                        'payment_failed_at' => $isRetryable ? null : now(),
                    ]);
                }

                if (! $isRetryable) {
                    $this->finalizeOrderStateWithinTransaction($lockedPayment);
                }
            }

            return [
                'is_idempotent' => false,
                'already_processing' => false,
                'order_id' => $lockedPayment->order_id,
                'payment_id' => $lockedPayment->id,
                'payment_reference' => $lockedPayment->payment_reference,
                'gateway_id' => $lockedPayment->payment_gateway_id,
                'status' => $gatewayResult['status'] ?? $lockedPayment->status->value,
                'payment_url' => $gatewayResult['payment_url'] ?? $lockedPayment->payment_url,
                'message' => $gatewayResult['message'] ?? 'Payment attempt completed.',
                'retryable' => $isRetryable,
            ];
        });
    }

    /**
     * Handle payment gateway browser callback.
     */
    public function handleCallback(string $gatewayId, Request $request): array
    {
        $strategy = $this->gatewayFactory->create($gatewayId);
        $result = $strategy->callback($request);

        $paymentId = $result['payment_id'] ?? $result['payment']?->id;
        if (! empty($paymentId)) {
            $isPaid = $result['success'] ?? false;
            $this->finalizePaymentStatus($paymentId, $isPaid ? PaymentStatus::COMPLETED : PaymentStatus::FAILED, $result);
            $result['payment'] = Payment::with(['order.orderItems', 'gateway'])->find($paymentId);
        }

        return $result;
    }

    /**
     * Handle payment gateway asynchronous webhook.
     */
    public function handleWebhook(string $gatewayId, Request $request): array
    {
        $strategy = $this->gatewayFactory->create($gatewayId);
        $result = $strategy->handleWebhook($request);

        $paymentId = $result['payment_id'] ?? $result['payment']?->id;
        if (! empty($paymentId)) {
            $isPaid = $result['success'] ?? false;
            $this->finalizePaymentStatus($paymentId, $isPaid ? PaymentStatus::COMPLETED : PaymentStatus::FAILED, $result);
            $result['payment'] = Payment::with(['order.orderItems', 'gateway'])->find($paymentId);
        }

        return $result;
    }

    /**
     * Concurrency-safe helper to update payment status and invoke order finalization.
     */
    public function finalizePaymentStatus(string $paymentId, PaymentStatus $targetStatus, array $gatewayData = []): void
    {
        DB::transaction(function () use ($paymentId, $targetStatus, $gatewayData) {
            $payment = Payment::where('id', $paymentId)->lockForUpdate()->first();
            if ($payment) {
                $this->finalizePaymentStatusWithinTransaction($payment, $targetStatus, $gatewayData);
            }
        });
    }

    /**
     * Internal non-transactional helper for updating payment status within an existing transaction.
     */
    protected function finalizePaymentStatusWithinTransaction(Payment $payment, PaymentStatus $targetStatus, array $gatewayData = []): void
    {
        if ($payment->status === PaymentStatus::COMPLETED) {
            return; // Already completed (idempotent callback/webhook guard)
        }

        if (! $payment->status->canTransitionTo($targetStatus)) {
            Log::warning("Invalid payment status transition attempt from {$payment->status->value} to {$targetStatus->value} for payment {$payment->id}");
            return;
        }

        $payment->update([
            'status' => $targetStatus,
            'transaction_id' => $gatewayData['transaction_id'] ?? $payment->transaction_id,
            'payment_completed_at' => ($targetStatus === PaymentStatus::COMPLETED) ? now() : $payment->payment_completed_at,
            'payment_failed_at' => ($targetStatus === PaymentStatus::FAILED) ? now() : $payment->payment_failed_at,
        ]);

        $this->finalizeOrderStateWithinTransaction($payment);
    }

    /**
     * Retry transient payment gateway failure using atomic claim and gateway idempotency.
     */
    public function retryPayment(string $paymentId): array
    {
        $payment = Payment::find($paymentId);

        if (! $payment) {
            return ['success' => false, 'message' => 'Payment record not found'];
        }

        if (! $payment->status->canBeRetried()) {
            return [
                'success' => false,
                'message' => "Payment is in '{$payment->status->value}' state and cannot be retried.",
                'status' => $payment->status->value,
            ];
        }

        return $this->executePaymentAttempt($payment);
    }

    /**
     * Mark payment as permanently failed and release reserved inventory.
     */
    public function markPaymentAsPermanentlyFailed(string $paymentId, string $reason): void
    {
        DB::transaction(function () use ($paymentId, $reason) {
            $payment = Payment::where('id', $paymentId)->lockForUpdate()->first();
            if ($payment && $payment->status !== PaymentStatus::COMPLETED) {
                if ($payment->status->canTransitionTo(PaymentStatus::FAILED)) {
                    $payment->update([
                        'status' => PaymentStatus::FAILED,
                        'failure_reason' => $reason,
                        'payment_failed_at' => now(),
                    ]);
                }
                $this->finalizeOrderStateWithinTransaction($payment);
            }
        });
    }

    /**
     * Public transaction wrapper for finalizeOrderStateWithinTransaction.
     */
    public function finalizeOrderState(string $paymentId): void
    {
        DB::transaction(function () use ($paymentId) {
            $payment = Payment::where('id', $paymentId)->lockForUpdate()->first();
            if ($payment) {
                $this->finalizeOrderStateWithinTransaction($payment);
            }
        });
    }

    /**
     * Internal non-transactional helper for Order and Inventory Settlement.
     * Uses deterministic row locking (Payment -> Order -> Products in ID order).
     */
    protected function finalizeOrderStateWithinTransaction(Payment $payment): void
    {
        if (! $payment->order_id) {
            return;
        }

        // Lock Order row
        $order = Order::where('id', $payment->order_id)->lockForUpdate()->first();

        if (! $order) {
            return;
        }

        // Lock Product rows in deterministic ID order (ORDER BY id ASC)
        $orderItemProductIds = $order->orderItems()->pluck('product_id')->sort()->values()->toArray();
        $products = Product::whereIn('id', $orderItemProductIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        // --- SUCCESS FLOW ---
        if ($payment->status === PaymentStatus::COMPLETED) {
            if ($order->status === OrderStatus::COMPLETED) {
                // Idempotency Check: Already finalized and stock already converted
                return;
            }

            // Convert reserved quantity into sold stock (decrease quantity AND reserved_quantity)
            foreach ($order->orderItems as $item) {
                if (isset($products[$item->product_id])) {
                    /** @var Product $product */
                    $product = $products[$item->product_id];

                    if ($product->reserved_quantity < $item->quantity) {
                        throw new RuntimeException("Inventory invariant violation: reserved_quantity ({$product->reserved_quantity}) < order quantity ({$item->quantity}) for product ID {$product->id}");
                    }

                    $newQuantity = $product->quantity - $item->quantity;
                    $newReserved = $product->reserved_quantity - $item->quantity;

                    $product->update([
                        'quantity' => $newQuantity,
                        'reserved_quantity' => $newReserved,
                    ]);
                }
            }

            $order->update(['status' => OrderStatus::COMPLETED]);

            // Dispatch PaymentSuccessEvent AFTER database transaction commits
            DB::afterCommit(function () use ($payment) {
                PaymentSuccessEvent::dispatch($payment->fresh());
            });

        // --- FAILURE / CANCELLATION / EXPIRATION FLOW ---
        } elseif (in_array($payment->status, [PaymentStatus::FAILED, PaymentStatus::CANCELLED, PaymentStatus::EXPIRED])) {
            if ($order->status === OrderStatus::CANCELLED) {
                // Idempotency Check: Reserved stock already released
                return;
            }

            // Release reserved quantity ONLY (do NOT decrease physical quantity)
            foreach ($order->orderItems as $item) {
                if (isset($products[$item->product_id])) {
                    /** @var Product $product */
                    $product = $products[$item->product_id];

                    $newReserved = max(0, $product->reserved_quantity - $item->quantity);

                    $product->update([
                        'reserved_quantity' => $newReserved,
                    ]);
                }
            }

            $order->update(['status' => OrderStatus::CANCELLED]);
        }
    }

    /**
     * Format response for idempotent request.
     */
    protected function formatIdempotentResponse(Payment $existingPayment): array
    {
        return [
            'is_idempotent' => true,
            'already_processing' => false,
            'order_id' => $existingPayment->order_id,
            'payment_id' => $existingPayment->id,
            'payment_reference' => $existingPayment->payment_reference,
            'gateway_id' => $existingPayment->payment_gateway_id,
            'status' => $existingPayment->status->value,
            'payment_url' => $existingPayment->payment_url,
            'message' => 'Idempotent request: returning existing payment details.',
        ];
    }
}
