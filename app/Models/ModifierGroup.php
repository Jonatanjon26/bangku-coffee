<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grup varian (Ukuran, Suhu, ...). print_always: pilihan bawaan tetap ditulis di tiket. */
#[Fillable(['name', 'print_always', 'sort'])]
class ModifierGroup extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['print_always' => 'boolean'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class)->orderBy('sort');
    }

    public function menuItems(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class)->withPivot('sort');
    }
}
