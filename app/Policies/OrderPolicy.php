<?php

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\Order;
use App\Models\User;
use App\Support\Settings;

/**
 * Transaksi di panel admin: lihat = hak "reports"; refund (hanya di hari pembayaran) = hak "approve" (atau
 * "reports" bila pengaturan "Refund perlu hak menyetujui" dimatikan); batal open bill = "approve".
 * Transaksi tidak pernah dibuat/diubah/dihapus dari panel.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::Reports);
    }

    public function view(User $user, Order $order): bool
    {
        return $user->hasPermission(Permission::Reports);
    }

    public function refund(User $user, Order $order): bool
    {
        $needed = app(Settings::class)->get('policy.refund_needs_approval') ? Permission::Approve : Permission::Reports;

        return $order->refundableToday() && $user->hasPermission($needed);
    }

    public function void(User $user, Order $order): bool
    {
        return $order->status === OrderStatus::Open && $user->hasPermission(Permission::Approve);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return false;
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
