<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case EXPIRED = 'expired';
    case DISPUTED = 'disputed';

    /**
     * Determine if a payment in this status is allowed to be retried.
     */
    public function canBeRetried(): bool
    {
        return match ($this) {
            self::PENDING, self::PROCESSING, self::FAILED => true,
            self::COMPLETED, self::CANCELLED, self::EXPIRED, self::REFUNDED, self::PARTIALLY_REFUNDED, self::DISPUTED => false,
        };
    }

    /**
     * Enforce strict, valid payment state transitions.
     */
    public function canTransitionTo(PaymentStatus $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::PENDING => in_array($target, [self::PROCESSING, self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED]),
            self::PROCESSING => in_array($target, [self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED]),
            self::FAILED => in_array($target, [self::PROCESSING, self::CANCELLED, self::EXPIRED]),
            self::COMPLETED => in_array($target, [self::REFUNDED, self::PARTIALLY_REFUNDED, self::DISPUTED]),
            self::CANCELLED, self::EXPIRED, self::REFUNDED, self::PARTIALLY_REFUNDED, self::DISPUTED => false,
        };
    }
}
