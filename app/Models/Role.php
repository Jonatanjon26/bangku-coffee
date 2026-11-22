<?php

namespace App\Models;

use App\Enums\Permission;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'sort'])]
class Role extends Model
{
    use Audited, HasFactory;

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return $this->rolePermissions
            ->map(fn (RolePermission $p) => Permission::tryFrom($p->permission))
            ->filter()
            ->values()
            ->all();
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->rolePermissions->contains('permission', $permission->value);
    }

    /** @param  iterable<Permission>  $permissions */
    public function syncPermissions(iterable $permissions): void
    {
        $values = collect($permissions)->map(fn (Permission $p) => $p->value)->unique()->sort()->values();
        $old = $this->rolePermissions()->pluck('permission')->sort()->values();

        if ($old->all() === $values->all()) {
            return;
        }

        $this->rolePermissions()->whereNotIn('permission', $values)->delete();
        $values->each(fn (string $value) => $this->rolePermissions()->firstOrCreate(['permission' => $value]));
        $this->unsetRelation('rolePermissions');

        AuditLog::record('role.permissions_updated', $this, ['permissions' => $old->all()], ['permissions' => $values->all()]);
    }
}
