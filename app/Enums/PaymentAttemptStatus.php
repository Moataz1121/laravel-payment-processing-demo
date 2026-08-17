<?php

namespace App\Enums;

enum PaymentAttemptStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
