<?php

namespace App\Services\PaymentHandlers\Strategies;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\PaymentHandlers\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StripePaymentGateway implements PaymentGatewayInterface
{
    public function processPayment(Payment $payment): array
    {
        $attemptNumber = $payment->attempts()->count() + 1;
        $transactionId = 'cs_test_' . Str::random(24);
        $checkoutUrl = config('app.url') . "/api/payments/callback/{$payment->payment_gateway_id}?session_id={$transactionId}&payment_id={$payment->id}";

        PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => $attemptNumber,
            'status' => PaymentAttemptStatus::PROCESSING,
            'request_payload' => [
                'provider' => 'stripe',
                'line_items' => [
                    [
                        'amount' => (int) ($payment->amount * 100),
                        'currency' => strtolower($payment->currency),
                        'quantity' => 1,
                    ],
                ],
                'mode' => 'payment',
                'success_url' => $checkoutUrl . '&status=success',
                'cancel_url' => $checkoutUrl . '&status=cancel',
            ],
            'started_at' => now(),
        ]);

        $payment->update([
            'status' => PaymentStatus::PROCESSING,
            'transaction_id' => $transactionId,
            'payment_url' => $checkoutUrl,
            'payment_initiated_at' => now(),
        ]);

        return [
            'success' => true,
            'payment_id' => $payment->id,
            'transaction_id' => $transactionId,
            'status' => PaymentStatus::PROCESSING->value,
            'payment_url' => $checkoutUrl,
            'message' => 'Stripe checkout session created',
        ];
    }

    public function callback(Request $request): array
    {
        $paymentId = $request->query('payment_id');
        $statusParam = $request->query('status', 'success');

        $payment = Payment::find($paymentId);

        if (! $payment) {
            return [
                'success' => false,
                'payment_id' => '',
                'status' => PaymentStatus::FAILED->value,
                'message' => 'Payment reference not found',
            ];
        }

        $isSuccess = ($statusParam === 'success');
        $finalStatus = $isSuccess ? PaymentStatus::COMPLETED : PaymentStatus::CANCELLED;

        $payment->update([
            'status' => $finalStatus,
            'payment_completed_at' => $isSuccess ? now() : null,
            'payment_failed_at' => $isSuccess ? null : now(),
        ]);

        PaymentAttempt::create([
            'payment_id' => $payment->id,
            'attempt_number' => $payment->attempts()->count() + 1,
            'status' => $isSuccess ? PaymentAttemptStatus::SUCCEEDED : PaymentAttemptStatus::FAILED,
            'response_payload' => $request->all(),
            'finished_at' => now(),
        ]);

        return [
            'success' => $isSuccess,
            'payment_id' => $payment->id,
            'status' => $finalStatus->value,
            'message' => $isSuccess ? 'Stripe payment checkout completed' : 'Stripe payment cancelled',
        ];
    }

    public function handleWebhook(Request $request): array
    {
        $payload = $request->all();
        $event = $payload['type'] ?? 'checkout.session.completed';
        $sessionId = $payload['data']['object']['id'] ?? null;

        if ($sessionId && $payment = Payment::where('transaction_id', $sessionId)->first()) {
            $isCompleted = ($event === 'checkout.session.completed');
            $payment->update([
                'status' => $isCompleted ? PaymentStatus::COMPLETED : PaymentStatus::FAILED,
                'payment_completed_at' => $isCompleted ? now() : null,
            ]);
        }

        return [
            'success' => true,
            'payment_id' => $payment?->id,
            'event_type' => $event,
            'message' => 'Stripe webhook event processed',
        ];
    }

    public function processRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        $newRefunded = $payment->refunded_amount + $amount;
        $status = ($newRefunded >= $payment->amount) ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED;

        $payment->update([
            'refunded_amount' => $newRefunded,
            'refund_attempts' => $payment->refund_attempts + 1,
            'status' => $status,
        ]);

        return [
            'success' => true,
            'refunded_amount' => $amount,
            'message' => 'Stripe refund executed',
        ];
    }
}
