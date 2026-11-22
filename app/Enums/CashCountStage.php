<?php

namespace App\Enums;

enum CashCountStage: string
{
    use HasLabel;

    case Opening = 'opening';
    case Closing = 'closing';

    protected static function translationKey(): string
    {
        return 'cash_count_stage';
    }
}
