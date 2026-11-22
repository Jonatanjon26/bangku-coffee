<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Peran: hak "staff". Karyawan bukan super-admin tidak bisa mengubah perannya sendiri
 * atau peran yang memberi hak yang tidak ia miliki (mencegah menaikkan hak sendiri).
 */
class RolePolicy extends PermissionPolicy
{
    protected function permission(): Permission
    {
        return Permission::Staff;
    }

    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) && self::canManage($user, $model);
    }

    protected function canDelete(Model $model): bool
    {
        return ! $model->users()->exists();
    }

    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && self::canManage($user, $model);
    }

    public static function canManage(User $user, Role $role): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $user->role_id !== $role->id && self::grantable($user, $role->permissions());
    }

    /** @param  list<Permission>  $permissions */
    public static function grantable(User $user, array $permissions): bool
    {
        return $user->is_admin || collect($permissions)->every(fn (Permission $p) => $user->hasPermission($p));
    }
}
