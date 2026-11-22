<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Karyawan: hak "staff". Super-admin hanya bisa diubah super-admin, dan karyawan dengan peran
 * yang punya hak lebih dari pengubah tidak bisa diubah (mencegah ambil alih akun lewat ganti
 * kata sandi/PIN). Karyawan tidak dihapus (riwayat transaksi), cukup dinonaktifkan.
 */
class UserPolicy extends PermissionPolicy
{
    protected function permission(): Permission
    {
        return Permission::Staff;
    }

    public function update(User $user, Model $model): bool
    {
        if (! parent::update($user, $model)) {
            return false;
        }

        return $user->is_admin || (! $model->is_admin && RolePolicy::grantable($user, $model->role?->permissions() ?? []));
    }

    protected function canDelete(Model $model): bool
    {
        return false;
    }
}
