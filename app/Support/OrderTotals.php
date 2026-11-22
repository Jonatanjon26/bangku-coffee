<?php

namespace App\Support;

use App\Enums\DiscountType;

/**
 * Hitung total pesanan (pajak restoran):
 * diskon dari subtotal, lalu potongan tukar poin; service dari (subtotal − diskon − poin);
 * PB1 dari (subtotal − diskon − poin + service).
 * Pembulatan ke rupiah terdekat di setiap langkah, sama dengan mockup kasir.
 */
final readonly class OrderTotals
{
    public function __construct(
        public int $subtotal,
        public int $discount,
        public float $serviceRate,
        public int $service,
        public float $taxRate,
        public int $tax,
        public int $total,
        public int $points = 0,
    ) {}

    public static function calculate(int $subtotal, ?DiscountType $discountType, int $discountValue, float $serviceRate, float $taxRate, int $pointsValue = 0): self
    {
        $discount = match ($discountType) {
            DiscountType::Percent => (int) round($subtotal * $discountValue / 100),
            DiscountType::Amount => $discountValue,
            null => 0,
        };
        $discount = max(0, min($subtotal, $discount));
        $points = max(0, min($subtotal - $discount, $pointsValue));
        $base = $subtotal - $discount - $points;
        $service = (int) round($base * $serviceRate / 100);
        $tax = (int) round(($base + $service) * $taxRate / 100);

        return new self($subtotal, $discount, $serviceRate, $service, $taxRate, $tax, $base + $service + $tax, $points);
    }

    /** Persentase diskon terhadap subtotal (untuk batas persetujuan diskon). */
    public function discountPercent(): float
    {
        return $this->subtotal > 0 ? $this->discount * 100 / $this->subtotal : 0.0;
    }
}
