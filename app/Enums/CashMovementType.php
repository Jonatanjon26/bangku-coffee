<?php

namespace App\Enums;

enum CashMovementType: string
{
    use HasLabel;

    case In = 'in';
    case Out = 'out';

    protected static function translationKey(): string
    {
        return 'cash_movement_type';
    }
}
