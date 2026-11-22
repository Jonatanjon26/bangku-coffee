<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['category_id', 'name', 'price', 'icon', 'image_path', 'is_active', 'is_sold_out', 'is_best_seller', 'sort'])]
class MenuItem extends Model
{
    use Audited, SoftDeletes;

    // Perubahan harga dicatat di log audit.
    protected static function booted(): void
    {
        static::updated(function (self $model) {
            if ($model->wasChanged('price')) {
                AuditLog::record('menu_item.price_changed', $model, ['price' => $model->getOriginal('price')], ['price' => $model->price]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_active' => 'boolean',
            'is_sold_out' => 'boolean',
            'is_best_seller' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class)->withPivot('sort')->orderBy('modifier_groups.sort');
    }

    /** Tampil di kasir (aktif). Habis tetap tampil, tapi tidak bisa dipilih. */
    public function scopeOnPos(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
