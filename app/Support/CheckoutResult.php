<?php

namespace App\Support;

use App\Models\Order;

/**
 * Hasil simpan/bayar. $ticket = item yang belum pernah tercetak di tiket barista
 * (untuk pesanan tersimpan: hanya tambahan → dicetak sebagai TAMBAHAN).
 */
final readonly class CheckoutResult
{
    /** @param  list<array{name: string, labels: list<string>, qty: int, station: ?string}>  $ticket */
    public function __construct(
        public Order $order,
        public array $ticket,
        public bool $isAddition,
    ) {}
}
