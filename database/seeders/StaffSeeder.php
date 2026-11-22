<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Karyawan contoh dari mockup. Owner (akun ADMIN_EMAIL) diberi peran Owner di semua lingkungan;
 * karyawan contoh, PIN, dan kata sandi contoh hanya di local/testing.
 * Idempoten: PIN & kata sandi yang sudah ada tidak ditimpa.
 */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Role::pluck('id', 'name');
        $owner = filled(config('admin.email')) ? User::firstWhere('email', config('admin.email')) : null;

        if ($owner && ! $owner->role_id && isset($roles['Owner'])) {
            $owner->forceFill(['role_id' => $roles['Owner']])->save();
        }

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        if ($owner && ! $owner->pin) {
            $owner->forceFill(['pin' => '9999'])->save();
        }

        $staff = [
            ['name' => 'Rani', 'role' => 'Kasir', 'pin' => '1111'],
            ['name' => 'Dimas', 'role' => 'Kasir', 'pin' => '2222'],
            ['name' => 'Sinta', 'role' => 'Kepala toko', 'pin' => '3333', 'email' => 'sinta@bangkucoffee.id', 'password' => 'BangkuSinta2026'],
        ];

        foreach ($staff as $s) {
            $user = isset($s['email']) ? User::firstOrNew(['email' => $s['email']]) : User::firstOrNew(['name' => $s['name'], 'email' => null]);

            if ($user->exists) {
                continue;
            }

            $user->forceFill([
                'name' => $s['name'],
                'role_id' => $roles[$s['role']] ?? null,
                'pin' => $s['pin'],
                'password' => $s['password'] ?? null,
                'email_verified_at' => isset($s['email']) ? now() : null,
                'is_active' => true,
            ])->save();
        }
    }
}
