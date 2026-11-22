<?php

namespace App\Enums;

/** Jenis daftar alasan (isi daftarnya diatur di admin). */
enum ReasonType: string
{
    use HasLabel;

    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case Refund = 'refund';
    case Void = 'void';
    case Variance = 'variance';

    protected static function translationKey(): string
    {
        return 'reason_type';
    }
}
