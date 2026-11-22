<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['modifier_group_id', 'modifier_option_id', 'group_name', 'option_name', 'price_delta', 'is_default', 'print_always'])]
class OrderItemModifier extends Model
{
    protected function casts(): array
    {
        return [
            'price_delta' => 'integer',
            'is_default' => 'boolean',
            'print_always' => 'boolean',
        ];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
