<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Tanggal bisnis (hari dagang) dengan jam pergantian hari (pengaturan orders.day_change_hour): transaksi
 * sebelum jam itu dihitung ke hari sebelumnya — toko yang buka lewat 00.00 (mis. akhir pekan) tetap satu
 * hari dagang untuk antrean, kode pesanan, laporan, refund hari yang sama, open bill lewat hari & settlement EDC.
 * Tidak bergantung pada shift (shift yang lupa ditutup tidak menahan tanggal).
 */
class BusinessDate
{
    public static function today(?CarbonInterface $at = null): CarbonImmutable
    {
        $hour = max(0, min(12, (int) app(Settings::class)->get('orders.day_change_hour')));

        return CarbonImmutable::instance($at ?? CarbonImmutable::now())->subHours($hour)->startOfDay();
    }
}
