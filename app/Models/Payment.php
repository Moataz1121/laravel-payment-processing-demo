<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory, HasUids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'payment_gateway_id',
        'order_id',
        'user_id',
        'payment_reference',
        'idempotency_key',
        'transaction_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'payment_initiated_at',
        'payment_completed_at',
        'payment_failed_at',
        'expires_at',
        'payment_url',
        'success_url',
        'failure_url',
        'cancel_url',
        'failure_reason',
        'failure_message',
        'gateway_error_code',
        'refunded_amount',
        'refund_attempts',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'refund_attempts' => 'integer',
            'payment_initiated_at' => 'datetime',
            'payment_completed_at' => 'datetime',
            'payment_failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
