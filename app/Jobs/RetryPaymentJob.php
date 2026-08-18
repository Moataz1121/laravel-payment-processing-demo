<?php

namespace App\Jobs;

use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max retry attempts for transient payment failures.
     */
    public int $tries = 3;

    /**
     * Backoff strategy (seconds).
     */
    public array $backoff = [5, 15, 30];

    public function __construct(
        public string $paymentId
    ) {}

    public function handle(PaymentService $paymentService): void
    {
        Log::info("Executing RetryPaymentJob attempt {$this->attempts()}", [
            'payment_id' => $this->paymentId,
        ]);

        $result = $paymentService->retryPayment($this->paymentId);

        if (! ($result['success'] ?? false) && ($result['retryable'] ?? false)) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 10);
            } else {
                $paymentService->markPaymentAsPermanentlyFailed($this->paymentId, 'Max retry attempts exhausted.');
            }
        }
    }

    /**
     * Handle job failure after max attempts reached.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("RetryPaymentJob failed permanently for payment {$this->paymentId}", [
            'error' => $exception->getMessage(),
        ]);

        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);
        $paymentService->markPaymentAsPermanentlyFailed($this->paymentId, $exception->getMessage());
    }
}
