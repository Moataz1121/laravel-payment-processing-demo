<?php

namespace App\Repositories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function findByIdempotencyKey(int $userId, string $idempotencyKey): ?Payment
    {
        return Payment::where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function findByReference(string $reference): ?Payment
    {
        return Payment::where('payment_reference', $reference)->first();
    }

    public function createPayment(array $data): Payment
    {
        return Payment::create($data);
    }

    public function updateStatus(Payment $payment, PaymentStatus $status, array $attributes = []): bool
    {
        return $payment->update(array_merge([
            'status' => $status,
        ], $attributes));
    }
}
