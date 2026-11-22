<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Model;

/** Menu, kategori & varian: hak "menu". */
class MenuPolicy extends PermissionPolicy
{
    protected function permission(): Permission
    {
        return Permission::Menu;
    }

    protected function canDelete(Model $model): bool
    {
        return match (true) {
            $model instanceof Category => ! MenuItem::withTrashed()->where('category_id', $model->id)->exists(),
            $model instanceof MenuItem => ! $model->trashed(),
            default => true,
        };
    }
}
