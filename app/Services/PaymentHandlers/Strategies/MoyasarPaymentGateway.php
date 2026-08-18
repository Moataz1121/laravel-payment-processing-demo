<?php

namespace App\Services\PaymentHandlers\Strategies;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentHandlers\Contracts\PaymentGatewayInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MoyasarPaymentGateway implements PaymentGatewayInterface
{
    protected string $secretKey;
    protected string $publicKey;
    protected string $baseUrl = 'https://api.moyasar.com/v1';

    public function __construct()
    {
        $this->secretKey = config('services.moyasar.secret', env('MOYASAR_SECRET_KEY', 'sk_test_xxx'));
        $this->publicKey = config('services.moyasar.public', env('MOYASAR_PUBLIC_KEY', 'pk_test_xxx'));
    }

    protected function getSecretKey(?Payment $payment): string
    {
        $creds = $payment?->paymentGateway?->creds;
        return $creds['secret_key'] ?? $this->secretKey;
    }

    public function processPayment(Payment $payment, ?string $gatewayIdempotencyKey = null): array
    {
        $secretKey = $this->getSecretKey($payment);
        $amountInHalalas = (int) round($payment->amount * 100);
        $idempotencyKey = $gatewayIdempotencyKey ?? ("pay_idem_" . $payment->id);

        $callbackUrl = config('app.url') . "/api/payments/callback/{$payment->payment_gateway_id}?payment_id={$payment->id}&payment_reference={$payment->payment_reference}";

        $payload = [
            'amount' => $amountInHalalas,
            'currency' => $payment->currency ?? 'SAR',
            'description' => 'Order #' . $payment->order_id,
            'callback_url' => $callbackUrl,
            'success_url' => $callbackUrl,
            'back_url' => $callbackUrl,
            'expired_at' => now()->addHours(1)->toIso8601String(),
            'metadata' => [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'payment_reference' => $payment->payment_reference,
                'idempotency_key' => $idempotencyKey,
            ],
        ];

        Log::info('Moyasar processPayment initiated', [
            'payment_id' => $payment->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        try {
            if ($secretKey && $secretKey !== 'sk_test_xxx') {
                $response = Http::withBasicAuth($secretKey, '')
                    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                    ->timeout(10)
                    ->post("{$this->baseUrl}/invoices", $payload);

                if ($response->successful()) {
                    $data = $response->json();
                    $paymentUrl = $data['url'] ?? null;
                    $invoiceId = $data['id'] ?? null;

                    return [
                        'success' => true,
                        'payment_id' => $payment->id,
                        'transaction_id' => $invoiceId,
                        'status' => PaymentStatus::PROCESSING->value,
                        'payment_url' => $paymentUrl,
                        'message' => 'Moyasar invoice session created',
                        'data' => $data,
                        'retryable' => false,
                    ];
                }

                Log::error('Moyasar create invoice failed', ['response' => $response->body()]);
                return [
                    'success' => false,
                    'payment_id' => $payment->id,
                    'transaction_id' => null,
                    'status' => PaymentStatus::FAILED->value,
                    'payment_url' => null,
                    'message' => 'Moyasar API error: ' . $response->body(),
                    'retryable' => $response->status() >= 500, // Gateway server errors are retryable
                ];
            }
        } catch (ConnectionException $e) {
            Log::error('Moyasar Connection Exception / Timeout: ' . $e->getMessage());
            return [
                'success' => false,
                'payment_id' => $payment->id,
                'transaction_id' => null,
                'status' => PaymentStatus::PROCESSING->value, // Payment state unknown (timed out)
                'payment_url' => null,
                'message' => 'Moyasar gateway connection timed out. State pending verification.',
                'retryable' => true,
            ];
        } catch (\Throwable $e) {
            Log::error('Moyasar Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'payment_id' => $payment->id,
                'transaction_id' => null,
                'status' => PaymentStatus::FAILED->value,
                'payment_url' => null,
                'message' => 'Moyasar exception: ' . $e->getMessage(),
                'retryable' => true,
            ];
        }

        // Fallback checkout URL (for test mode / local environment)
        $fallbackUrl = $callbackUrl . "&status=paid";

        return [
            'success' => true,
            'payment_id' => $payment->id,
            'transaction_id' => 'TXN-FALLBACK-' . $payment->id,
            'status' => PaymentStatus::PROCESSING->value,
            'payment_url' => $fallbackUrl,
            'message' => 'Moyasar fallback checkout URL generated',
            'retryable' => false,
        ];
    }

    public function callback(Request $request): array
    {
        Log::info('Moyasar Callback triggered', ['params' => $request->all()]);

        $paymentId = $request->get('payment_id');
        $paymentRef = $request->get('payment_reference') ?? $request->get('reference');
        $invoiceId = $request->get('invoice_id') ?? $request->get('id');

        $payment = null;
        if ($paymentId) {
            $payment = Payment::find($paymentId);
        }
        if (! $payment && $paymentRef) {
            $payment = Payment::where('payment_reference', $paymentRef)->first();
        }
        if (! $payment && $invoiceId) {
            $payment = Payment::where('transaction_id', $invoiceId)->first();
        }

        $secretKey = $this->getSecretKey($payment);
        $isPaid = false;

        if ($invoiceId && $secretKey && $secretKey !== 'sk_test_xxx') {
            try {
                $response = Http::withBasicAuth($secretKey, '')
                    ->timeout(10)
                    ->get("{$this->baseUrl}/invoices/{$invoiceId}");

                if ($response->successful()) {
                    $data = $response->json();
                    if (! $payment && isset($data['metadata']['payment_id'])) {
                        $payment = Payment::find($data['metadata']['payment_id']);
                    }
                    if (isset($data['status']) && in_array($data['status'], ['paid', 'completed'])) {
                        $isPaid = true;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Moyasar verification exception: ' . $e->getMessage());
            }
        }

        $statusParam = strtolower((string) $request->get('status', ''));
        $messageParam = strtoupper((string) $request->get('message', ''));

        if (! $isPaid && (in_array($statusParam, ['paid', 'success', 'completed', 'captured']) || $messageParam === 'APPROVED')) {
            $isPaid = true;
        }

        if (! $payment) {
            return [
                'success' => false,
                'payment' => null,
                'payment_id' => '',
                'status' => PaymentStatus::FAILED->value,
                'message' => 'Moyasar payment reference not found',
            ];
        }

        $finalStatus = $isPaid ? PaymentStatus::COMPLETED : PaymentStatus::FAILED;

        return [
            'success' => $isPaid,
            'payment_id' => $payment->id,
            'status' => $finalStatus->value,
            'message' => $isPaid ? 'Moyasar payment verified and completed successfully' : 'Moyasar payment failed or cancelled',
        ];
    }

    public function handleWebhook(Request $request): array
    {
        return $this->callback($request);
    }

    public function processRefund(Payment $payment, float $amount, ?string $reason = null): array
    {
        $secretKey = $this->getSecretKey($payment);
        $amountInHalalas = (int) round($amount * 100);

        try {
            $response = Http::withBasicAuth($secretKey, '')
                ->post("{$this->baseUrl}/charges/{$payment->transaction_id}/refund", [
                    'amount' => $amountInHalalas,
                    'reason' => $reason ?? 'Customer requested refund',
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'refunded_amount' => $amount,
                    'message' => 'Moyasar refund executed successfully',
                ];
            }

            return [
                'success' => false,
                'refunded_amount' => 0.00,
                'message' => 'Moyasar refund failed: ' . $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'refunded_amount' => 0.00,
                'message' => 'Moyasar refund exception: ' . $e->getMessage(),
            ];
        }
    }
}
