<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Shift;
use App\Models\User;

/**
 * Shift & kas di panel: lihat = hak "reports"; tandai setoran diterima = hak "approve", bukan oleh
 * yang membuka/menutup shift itu sendiri (kecuali super admin). Tidak bisa diubah/dihapus.
 */
class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::Reports);
    }

    public function view(User $user, Shift $shift): bool
    {
        return $user->hasPermission(Permission::Reports);
    }

    public function receiveDeposit(User $user, Shift $shift): bool
    {
        $ownShift = in_array($user->id, [$shift->opened_by, $shift->closed_by], true);

        return ! $shift->isOpen() && $shift->deposit_received_at === null && $user->hasPermission(Permission::Approve)
            && (! $ownShift || $user->is_admin);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Shift $shift): bool
    {
        return false;
    }

    public function delete(User $user, Shift $shift): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
