<?php

namespace App\Services\PaymentHandlers\Contracts;

use App\Models\Payment;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * Process payment request with provider API using gateway-level idempotency key.
     *
     * @return array{
     *     success: bool,
     *     payment_id: string,
     *     transaction_id: string|null,
     *     status: string,
     *     payment_url: string|null,
     *     message: string,
     *     retryable?: bool
     * }
     */
    public function processPayment(Payment $payment, ?string $gatewayIdempotencyKey = null): array;

    /**
     * Handle browser callback after user completes/cancels payment on gateway portal.
     *
     * @return array{
     *     success: bool,
     *     payment_id: string,
     *     status: string,
     *     message: string
     * }
     */
    public function callback(Request $request): array;

    /**
     * Process asynchronous server-to-server webhook events.
     *
     * @return array{
     *     success: bool,
     *     payment_id: string|null,
     *     event_type: string|null,
     *     message: string
     * }
     */
    public function handleWebhook(Request $request): array;

    /**
     * Process refund for a completed payment.
     *
     * @return array{
     *     success: bool,
     *     refunded_amount: float,
     *     message: string
     * }
     */
    public function processRefund(Payment $payment, float $amount, ?string $reason = null): array;
}
