<?php

namespace App\Enums;

/** open = tersimpan (open bill, bayar nanti). */
enum OrderStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Paid = 'paid';
    case Voided = 'voided';
    case Refunded = 'refunded';

    protected static function translationKey(): string
    {
        return 'order_status';
    }
}
