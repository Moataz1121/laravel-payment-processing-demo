<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';
}
