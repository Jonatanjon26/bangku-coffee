<?php

namespace App\Enums;

/** cash = laci uang (ada kembalian); edc = mesin EDC/QRIS (dicocokkan dengan settlement). */
enum PaymentKind: string
{
    use HasLabel;

    case Cash = 'cash';
    case Edc = 'edc';

    protected static function translationKey(): string
    {
        return 'payment_kind';
    }
}
