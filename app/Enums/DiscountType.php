<?php

namespace App\Enums;

enum DiscountType: string
{
    use HasLabel;

    case Percent = 'percent';
    case Amount = 'amount';

    protected static function translationKey(): string
    {
        return 'discount_type';
    }
}
