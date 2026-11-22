<?php

namespace Tests\Unit;

use App\Enums\DiscountType;
use App\Support\OrderTotals;
use PHPUnit\Framework\TestCase;

class OrderTotalsTest extends TestCase
{
    public function test_tanpa_diskon_pajak_dan_service_total_sama_dengan_subtotal(): void
    {
        $t = OrderTotals::calculate(45000, null, 0, 0, 0);

        $this->assertSame([45000, 0, 0, 0, 45000], [$t->subtotal, $t->discount, $t->service, $t->tax, $t->total]);
    }

    public function test_service_dari_subtotal_dikurangi_diskon_dan_pb1_dari_dasar_plus_service(): void
    {
        // 100.000 − 10% = 90.000; service 5% = 4.500; PB1 10% dari 94.500 = 9.450.
        $t = OrderTotals::calculate(100000, DiscountType::Percent, 10, 5, 10);

        $this->assertSame(10000, $t->discount);
        $this->assertSame(4500, $t->service);
        $this->assertSame(9450, $t->tax);
        $this->assertSame(103950, $t->total);
    }

    public function test_diskon_rupiah_tidak_melebihi_subtotal(): void
    {
        $t = OrderTotals::calculate(8000, DiscountType::Amount, 10000, 5, 10);

        $this->assertSame(8000, $t->discount);
        $this->assertSame(0, $t->total);
    }

    public function test_pembulatan_ke_rupiah_terdekat_seperti_mockup(): void
    {
        // 22.500 × 5% = 1.125 → service 1.125; (23.625) × 10% = 2.362,5 → 2.363 (Math.round).
        $t = OrderTotals::calculate(22500, null, 0, 5, 10);

        $this->assertSame(1125, $t->service);
        $this->assertSame(2363, $t->tax);
        $this->assertSame(25988, $t->total);
    }

    public function test_persen_diskon_untuk_batas_persetujuan(): void
    {
        $this->assertEqualsWithDelta(25.0, OrderTotals::calculate(40000, DiscountType::Amount, 10000, 0, 0)->discountPercent(), 0.001);
        $this->assertSame(0.0, OrderTotals::calculate(0, DiscountType::Percent, 10, 0, 0)->discountPercent());
    }

    public function test_poin_dipotong_setelah_diskon_sebelum_service_dan_pajak(): void
    {
        // Subtotal 100.000, diskon 10% = 10.000, poin 5.000 → dasar 85.000; service 5% = 4.250; PB1 10% dari 89.250 = 8.925.
        $t = OrderTotals::calculate(100000, DiscountType::Percent, 10, 5, 10, 5000);

        $this->assertSame([10000, 5000, 4250, 8925, 98175], [$t->discount, $t->points, $t->service, $t->tax, $t->total]);
    }

    public function test_poin_tidak_melebihi_sisa_tagihan(): void
    {
        $t = OrderTotals::calculate(20000, DiscountType::Amount, 15000, 0, 0, 10000);

        $this->assertSame([5000, 0], [$t->points, $t->total]);
    }
}
