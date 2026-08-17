<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'attempt_number',
        'status',
        'request_payload',
        'response_payload',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'attempt_number' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
