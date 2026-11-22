<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\CreateAction;
use Livewire\Livewire;

class StaffAdminTest extends AdminTestCase
{
    /** Kepala toko yang diberi hak kelola karyawan (tanpa hak pengaturan). */
    private function staffManager(): User
    {
        $role = Role::firstWhere('name', 'Kepala toko');
        $role->syncPermissions([...$role->permissions(), Permission::Staff]);

        return $this->staff('Kepala toko');
    }

    public function test_tambah_kasir_dengan_pin_terhash(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManageUsers::class)
            ->callAction(CreateAction::class, data: ['name' => 'Budi', 'role_id' => Role::firstWhere('name', 'Kasir')->id, 'pin' => '4567', 'is_active' => true])
            ->assertHasNoActionErrors();

        $budi = User::firstWhere('name', 'Budi');
        $this->assertNotSame('4567', $budi->pin);
        $this->assertTrue($budi->checkPin('4567'));
        $this->assertSame(4, $budi->pin_length);
        $this->assertNull($budi->email);

        $log = AuditLog::where('event', 'user.created')->where('auditable_id', $budi->id)->firstOrFail();
        $this->assertSame(User::AUDIT_MASK, $log->new_values['pin']);
        $this->assertStringNotContainsString('4567', json_encode($log->new_values));
    }

    public function test_pin_wajib_untuk_peran_kasir_dan_harus_sesuai_panjang_dan_unik(): void
    {
        $this->actingAs($this->owner());
        $kasir = Role::firstWhere('name', 'Kasir')->id;
        $this->staff('Kasir', ['pin' => '1111']);

        $create = fn (array $data) => Livewire::test(ManageUsers::class)->callAction(CreateAction::class, data: ['name' => 'Budi', 'role_id' => $kasir, 'is_active' => true] + $data);

        $create([])->assertHasActionErrors(['pin' => 'required']);
        $create(['pin' => '12345'])->assertHasActionErrors(['pin']);
        $create(['pin' => '12a4'])->assertHasActionErrors(['pin']);
        $create(['pin' => '1111'])->assertHasActionErrors(['pin']);
        $create(['pin' => '2468'])->assertHasNoActionErrors();
    }

    public function test_cek_pin_terpakai_dibatasi_supaya_pin_penyetuju_tidak_bisa_ditebak(): void
    {
        config(['pos.admin_pin_checks_max' => 10]);
        $this->staff('Owner', ['pin' => '9999']);
        $this->actingAs($this->owner());

        // Menebak 0000, 0001, … lewat validasi form: setelah 10 pengecekan, PIN tidak dicek lagi.
        $results = collect(range(0, 11))->map(fn (int $i) => UserResource::pinError(str_pad((string) (9990 + $i), 4, '0', STR_PAD_LEFT), null));

        $this->assertSame(__('admin.users.pin_taken'), $results[9]);
        $this->assertNull($results[0]);
        $this->assertSame(__('admin.users.pin_check_locked', ['minutes' => 1]), $results[10]);
        $this->assertSame(__('admin.users.pin_check_locked', ['minutes' => 1]), $results[11]);
        $this->assertTrue(AuditLog::where('event', 'auth.admin_pin_check_locked')->exists());

        // Pengguna panel lain tidak ikut terkunci.
        $this->actingAs($this->owner());
        $this->assertSame(__('admin.users.pin_taken'), UserResource::pinError('9999', null));
    }

    public function test_hak_tidak_dikenal_ditolak_sebagai_error_validasi(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManageRoles::class)
            ->callAction(CreateAction::class, data: ['name' => 'Aneh', 'permissions' => ['xyz']])
            ->assertHasActionErrors(['permissions']);
        $this->assertNull(Role::firstWhere('name', 'Aneh'));
    }

    public function test_barista_tanpa_kasir_tidak_wajib_pin(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManageUsers::class)
            ->callAction(CreateAction::class, data: ['name' => 'Tono', 'role_id' => Role::firstWhere('name', 'Barista')->id, 'is_active' => true])
            ->assertHasNoActionErrors();
    }

    public function test_email_panel_wajib_kata_sandi_kuat(): void
    {
        $this->actingAs($this->owner());
        $data = ['name' => 'Maya', 'role_id' => Role::firstWhere('name', 'Kepala toko')->id, 'pin' => '7777', 'email' => 'maya@bangkucoffee.id', 'is_active' => true];

        Livewire::test(ManageUsers::class)->callAction(CreateAction::class, data: $data)->assertHasActionErrors(['password' => 'required']);
        Livewire::test(ManageUsers::class)->callAction(CreateAction::class, data: $data + ['password' => 'pendek'])->assertHasActionErrors(['password']);
        Livewire::test(ManageUsers::class)->callAction(CreateAction::class, data: $data + ['password' => 'MayaBangku2026'])->assertHasNoActionErrors();

        $this->assertTrue(auth()->validate(['email' => 'maya@bangkucoffee.id', 'password' => 'MayaBangku2026']));
    }

    public function test_ubah_tanpa_isi_pin_tidak_mengubah_pin(): void
    {
        $this->actingAs($this->owner());
        $rani = $this->staff('Kasir', ['pin' => '1111', 'name' => 'Rani']);

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $rani, data: ['name' => 'Rani S', 'pin' => ''])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Rani S', $rani->fresh()->name);
        $this->assertTrue($rani->fresh()->checkPin('1111'));
    }

    public function test_tidak_bisa_memberi_peran_dengan_hak_yang_tidak_dimiliki(): void
    {
        $this->actingAs($this->staffManager());

        Livewire::test(ManageUsers::class)
            ->callAction(CreateAction::class, data: ['name' => 'Penyusup', 'role_id' => Role::firstWhere('name', 'Owner')->id, 'pin' => '8888', 'is_active' => true])
            ->assertHasActionErrors(['role_id']);

        $this->assertNull(User::firstWhere('name', 'Penyusup'));
    }

    public function test_super_admin_tidak_bisa_diubah_karyawan_biasa(): void
    {
        $owner = $this->owner();
        $this->actingAs($this->staffManager());

        Livewire::test(ManageUsers::class)->assertTableActionHidden('edit', $owner);
        $this->assertFalse(auth()->user()->can('update', $owner));
    }

    public function test_karyawan_dengan_hak_lebih_tinggi_tidak_bisa_diubah(): void
    {
        $ownerRoleUser = $this->staff('Owner', ['email' => 'owner2@bangkucoffee.id', 'password' => 'OwnerKedua2026']);
        $cashier = $this->staff('Kasir');
        $this->actingAs($this->staffManager());

        Livewire::test(ManageUsers::class)
            ->assertTableActionHidden('edit', $ownerRoleUser)
            ->assertTableActionVisible('edit', $cashier);
        $this->assertFalse(auth()->user()->can('update', $ownerRoleUser));
    }

    public function test_tidak_bisa_menonaktifkan_atau_mengganti_peran_sendiri(): void
    {
        $me = $this->staffManager();
        $this->actingAs($me);

        Livewire::test(ManageUsers::class)
            ->callTableAction('edit', $me, data: ['is_active' => false, 'role_id' => Role::firstWhere('name', 'Kasir')->id]);

        $this->assertTrue($me->fresh()->is_active);
        $this->assertSame('Kepala toko', $me->fresh()->role->name);
    }

    public function test_karyawan_tidak_bisa_dihapus(): void
    {
        $this->actingAs($this->owner());

        $this->assertFalse(auth()->user()->can('delete', $this->staff('Kasir')));
    }

    public function test_hak_peran_disimpan_dan_dicatat(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManageRoles::class)
            ->callAction(CreateAction::class, data: ['name' => 'Shift leader', 'permissions' => [Permission::Pos->value, Permission::Approve->value]])
            ->assertHasNoActionErrors();

        $role = Role::firstWhere('name', 'Shift leader');
        $this->assertEqualsCanonicalizing([Permission::Pos, Permission::Approve], $role->permissions());
        $this->assertTrue(AuditLog::where('event', 'role.permissions_updated')->where('auditable_id', $role->id)->exists());
    }

    public function test_tidak_bisa_memberi_hak_yang_tidak_dimiliki(): void
    {
        $this->actingAs($this->staffManager());

        Livewire::test(ManageRoles::class)
            ->callAction(CreateAction::class, data: ['name' => 'Pintu belakang', 'permissions' => [Permission::Settings->value]])
            ->assertHasActionErrors(['permissions']);

        $this->assertNull(Role::firstWhere('name', 'Pintu belakang'));
    }

    public function test_tidak_bisa_mengubah_peran_sendiri_atau_peran_yang_lebih_tinggi(): void
    {
        $me = $this->staffManager();
        $this->actingAs($me);

        Livewire::test(ManageRoles::class)
            ->assertTableActionHidden('edit', $me->role)
            ->assertTableActionHidden('edit', Role::firstWhere('name', 'Owner'))
            ->assertTableActionVisible('edit', Role::firstWhere('name', 'Kasir'));
    }

    public function test_peran_yang_masih_dipakai_tidak_bisa_dihapus(): void
    {
        $this->actingAs($this->owner());
        $this->staff('Barista');
        $unused = Role::create(['name' => 'Magang']);

        Livewire::test(ManageRoles::class)
            ->assertTableActionHidden('delete', Role::firstWhere('name', 'Barista'))
            ->callTableAction('delete', $unused);

        $this->assertModelMissing($unused);
    }
}
