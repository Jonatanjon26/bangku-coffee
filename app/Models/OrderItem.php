<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Item pesanan dengan salinan nama & harga. printed_quantity: sisanya dicetak sebagai TAMBAHAN. */
#[Fillable(['menu_item_id', 'name', 'category_name', 'station_name', 'base_price', 'unit_price', 'quantity', 'note', 'printed_quantity', 'sort'])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'base_price' => 'integer',
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'printed_quantity' => 'integer',
        ];
    }

    /** Label varian & catatan untuk tiket/struk: pilihan bawaan tidak ditulis, kecuali grup "selalu cetak". */
    public function labels(): array
    {
        return $this->modifiers
            ->filter(fn (OrderItemModifier $m) => ! $m->is_default || $m->print_always)
            ->pluck('option_name')
            ->merge($this->note ? ['"'.$this->note.'"'] : [])
            ->values()
            ->all();
    }

    public function lineTotal(): int
    {
        return $this->unit_price * $this->quantity;
    }

    /** Jumlah yang belum tercetak di tiket barista. */
    public function unprintedQuantity(): int
    {
        return max(0, $this->quantity - $this->printed_quantity);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class)->withTrashed();
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }
}
