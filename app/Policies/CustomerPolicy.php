<?php

namespace App\Policies;

use App\Enums\Permission;

/** Pelanggan: hak "customers". Hapus = hapus data atas permintaan (Customer::erase, transaksi tetap ada). */
class CustomerPolicy extends PermissionPolicy
{
    protected function permission(): Permission
    {
        return Permission::Customers;
    }
}
