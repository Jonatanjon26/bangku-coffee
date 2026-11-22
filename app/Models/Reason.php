<?php

namespace App\Models;

use App\Enums\ReasonType;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Daftar alasan (kas masuk/keluar, refund, batal, selisih) — diatur di admin. */
#[Fillable(['type', 'label', 'requires_note', 'is_active', 'sort'])]
class Reason extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['type' => ReasonType::class, 'requires_note' => 'boolean', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort');
    }

    public function scopeOfType(Builder $query, ReasonType $type): void
    {
        $query->where('type', $type);
    }
}
