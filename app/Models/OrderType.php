<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'needs_table', 'applies_service', 'is_active', 'sort'])]
class OrderType extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['needs_table' => 'boolean', 'applies_service' => 'boolean', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort');
    }
}
