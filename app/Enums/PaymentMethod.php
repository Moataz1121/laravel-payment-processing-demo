<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CARD = 'card';
    case WALLET = 'wallet';
    case BANK_TRANSFER = 'bank_transfer';
    case CASH_ON_DELIVERY = 'cash_on_delivery';
}
