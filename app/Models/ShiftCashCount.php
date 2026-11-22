<?php

namespace App\Models;

use App\Enums\CashCountStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hitungan uang per pecahan saat buka/tutup shift. denomination 0 = koin (total). */
#[Fillable(['stage', 'denomination', 'quantity', 'amount'])]
class ShiftCashCount extends Model
{
    public const COINS = 0;

    protected function casts(): array
    {
        return [
            'stage' => CashCountStage::class,
            'denomination' => 'integer',
            'quantity' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
