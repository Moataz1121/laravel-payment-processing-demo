<?php

namespace App\Listeners;

use App\Events\PaymentSuccessEvent;
use App\Models\PaymentNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendPaymentSuccessNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the payment success event idempotently.
     */
    public function handle(PaymentSuccessEvent $event): void
    {
        $payment = $event->payment;
        $notificationType = 'payment_success_email';

        try {
            // Check or insert notification record to prevent duplicate emails
            $alreadySent = PaymentNotification::where('payment_id', $payment->id)
                ->where('notification_type', $notificationType)
                ->exists();

            if ($alreadySent) {
                Log::info('Payment success notification already sent. Skipping duplicate.', [
                    'payment_id' => $payment->id,
                ]);
                return;
            }

            PaymentNotification::create([
                'payment_id' => $payment->id,
                'notification_type' => $notificationType,
                'sent_at' => now(),
            ]);

            Log::info('Payment success confirmation email sent successfully.', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
                'amount' => $payment->amount,
            ]);

        } catch (UniqueConstraintViolationException $e) {
            Log::info('Concurrent notification handler execution caught. Skipping duplicate email.', [
                'payment_id' => $payment->id,
            ]);
        }
    }
}
