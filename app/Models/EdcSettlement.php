<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pencocokan settlement EDC sekali sehari (tutup hari). */
#[Fillable(['business_date', 'shift_id', 'note'])]
class EdcSettlement extends Model
{
    protected function casts(): array
    {
        return ['business_date' => DateOnly::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EdcSettlementLine::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function hasDifference(): bool
    {
        return $this->lines->contains(fn (EdcSettlementLine $line) => $line->difference !== 0);
    }
}
