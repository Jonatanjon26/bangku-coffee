<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort'])]
class Station extends Model
{
    use Audited;

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
