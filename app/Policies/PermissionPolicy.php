<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Dasar policy: semua aksi butuh satu hak (Permission). Hapus hanya bila data belum dipakai
 * (lihat canDelete); data yang sudah dipakai cukup dinonaktifkan.
 */
abstract class PermissionPolicy
{
    abstract protected function permission(): Permission;

    protected function allowed(User $user): bool
    {
        return $user->hasPermission($this->permission());
    }

    /** Data yang sudah dipakai transaksi tidak boleh dihapus. */
    protected function canDelete(Model $model): bool
    {
        return true;
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->allowed($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->allowed($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->allowed($user) && $this->canDelete($model);
    }

    public function reorder(User $user): bool
    {
        return $this->allowed($user);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->allowed($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
