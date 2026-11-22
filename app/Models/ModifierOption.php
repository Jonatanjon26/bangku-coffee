<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['modifier_group_id', 'name', 'price_delta', 'is_default', 'sort', 'is_active'])]
class ModifierOption extends Model
{
    use Audited;

    // Perubahan harga dicatat di log audit.
    protected static function booted(): void
    {
        static::updated(function (self $model) {
            if ($model->wasChanged('price_delta')) {
                AuditLog::record('modifier_option.price_changed', $model, ['price_delta' => $model->getOriginal('price_delta')], ['price_delta' => $model->price_delta]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'price_delta' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}
