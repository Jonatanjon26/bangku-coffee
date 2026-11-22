<?php

namespace App\Models;

use App\Enums\CashMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kas masuk/keluar selama shift. Pelaku & penyetuju diisi kode server (tidak fillable). */
#[Fillable(['shift_id', 'type', 'amount', 'reason_id', 'reason_label', 'note'])]
class CashMovement extends Model
{
    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'amount' => 'integer',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
