<?php

namespace App\Services\PaymentHandlers\Strategies;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentHandlers\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Request;

class MockPaymentGateway implements PaymentGatewayInterface
{
    /**
     * Simulated gateway transaction store for testing idempotency across retries.
     */
    protected static array $transactionStore = [];

    public function processPayment(Payment $payment, ?string $gatewayIdempotencyKey = null): array
    {
        $idempotencyRef = $gatewayIdempotencyKey ?? ('pay_idem_' . $payment->id);

        // Gateway-level Idempotency Check: Return existing transaction if already processed by provider
        if (isset(static::$transactionStore[$idempotencyRef])) {
            return static::$transactionStore[$idempotencyRef];
        }

        $transactionId = 'TXN-MOCK-' . strtoupper(substr(md5($idempotencyRef), 0, 10));
        $paymentUrl = config('app.url') . "/api/payments/callback/{$payment->payment_gateway_id}?payment_reference={$payment->payment_reference}&status=success";

        $response = [
            'success' => true,
            'payment_id' => $payment->id,
            'transaction_id' => $transactionId,
            'status' => PaymentStatus::PROCESSING->value,
            'payment_url' => $paymentUrl,
            'message' => 'Mock payment initiated successfully',
            'idempotency_key' => $idempotencyRef,
            'retryable' => false,
        ];

        static::$transactionStore[$idempotencyRef] = $response;

        return $response;
    }

    public function callback(Request $request): array
    {
        $reference = $request->query('payment_reference');
        $statusParam = $request->query('status', 'success');

        $payment = Payment::where('payment_reference', $reference)->first();

        if (! $payment) {
            return [
                'success' => false,
                'payment_id' => '',
                'status' => PaymentStatus::FAILED->value,
                'message' => 'Payment reference not found',
            ];
        }

        $isSuccess = $statusParam === 'success';
        $newStatus = $isSuccess ? PaymentStatus::COMPLETED : PaymentStatus::FAILED;

        return [
            'success' => $isSuccess,
            'payment_id' => $payment->id,
            'status' => $newStatus->value,
            'message' => $isSuccess ? 'Payment completed via Mock Gateway' : 'Payment failed on Mock Gateway',
        ];
    }

    public function handleWebhook(Request $request): array
    {
        return $this->callback($request);
    }

    public function processRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        $newRefunded = $payment->refunded_amount + $amount;
        $status = ($newRefunded >= $payment->amount) ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED;

        return [
            'success' => true,
            'refunded_amount' => $amount,
            'message' => 'Refund processed successfully',
        ];
    }
}
