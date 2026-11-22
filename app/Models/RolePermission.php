<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['permission'])]
class RolePermission extends Model
{
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
