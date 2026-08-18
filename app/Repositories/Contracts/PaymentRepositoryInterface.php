<?php

namespace App\Repositories\Contracts;

use App\Enums\PaymentStatus;
use App\Models\Payment;

interface PaymentRepositoryInterface
{
    public function findByIdempotencyKey(int $userId, string $idempotencyKey): ?Payment;

    public function findByReference(string $reference): ?Payment;

    public function createPayment(array $data): Payment;

    public function updateStatus(Payment $payment, PaymentStatus $status, array $attributes = []): bool;
}
