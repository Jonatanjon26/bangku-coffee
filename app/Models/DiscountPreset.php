<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'value', 'is_active', 'sort'])]
class DiscountPreset extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['type' => DiscountType::class, 'value' => 'integer', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort');
    }
}
