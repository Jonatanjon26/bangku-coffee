<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_hak_berasal_dari_peran_dan_dicek_lewat_gate(): void
    {
        $cashier = User::factory()->withPermissions([Permission::Pos], 'Kasir')->create();
        $manager = User::factory()->withPermissions([Permission::Pos, Permission::Approve, Permission::Admin], 'Kepala toko')->create();

        $this->assertTrue($cashier->fresh()->can(Permission::Pos->value));
        $this->assertFalse($cashier->fresh()->can(Permission::Approve->value));
        $this->assertTrue(Gate::forUser($manager->fresh())->allows(Permission::Approve->value));
        $this->assertFalse(Gate::forUser($manager->fresh())->allows(Permission::Settings->value));
    }

    public function test_super_admin_punya_semua_hak_dan_nonaktif_tidak_punya_hak(): void
    {
        $admin = User::factory()->admin()->create();
        $inactive = User::factory()->admin()->inactive()->create();

        foreach (Permission::cases() as $permission) {
            $this->assertTrue($admin->hasPermission($permission));
            $this->assertFalse($inactive->hasPermission($permission));
        }
    }

    public function test_akses_panel_mengikuti_hak_admin(): void
    {
        $manager = User::factory()->withPermissions([Permission::Admin], 'Kepala toko')->create();
        $cashier = User::factory()->withPermissions([Permission::Pos], 'Kasir')->create();
        $inactiveManager = User::factory()->inactive()->withPermissions([Permission::Admin], 'Kepala toko')->create();

        $this->actingAs($manager->fresh())->get(route('filament.admin.pages.dashboard'))->assertOk();
        $this->actingAs($cashier->fresh())->get(route('filament.admin.pages.dashboard'))->assertForbidden();
        $this->actingAs($inactiveManager->fresh())->get(route('filament.admin.pages.dashboard'))->assertForbidden();
    }

    public function test_pin_disimpan_terhash_dan_tersembunyi(): void
    {
        $user = User::factory()->create(['pin' => '1234']);

        $this->assertNotSame('1234', $user->fresh()->pin);
        $this->assertTrue($user->fresh()->checkPin('1234'));
        $this->assertFalse($user->fresh()->checkPin('4321'));
        $this->assertArrayNotHasKey('pin', $user->fresh()->toArray());
        $this->assertFalse(User::factory()->create()->checkPin(''));
    }

    public function test_pin_unik_di_antara_karyawan_aktif(): void
    {
        $rani = User::factory()->create(['pin' => '1111']);
        User::factory()->inactive()->create(['pin' => '2222']);

        $this->assertTrue(User::pinInUse('1111'));
        $this->assertFalse(User::pinInUse('1111', exceptUserId: $rani->id));
        $this->assertFalse(User::pinInUse('2222'));
        $this->assertFalse(User::pinInUse('3333'));
    }

    public function test_daftar_karyawan_dengan_hak_tertentu(): void
    {
        $cashier = User::factory()->withPermissions([Permission::Pos], 'Kasir')->create();
        $barista = User::factory()->withPermissions([], 'Barista')->create();
        $admin = User::factory()->admin()->create();
        User::factory()->inactive()->withPermissions([Permission::Pos], 'Kasir')->create();

        $this->assertEqualsCanonicalizing([$cashier->id, $admin->id], User::withPermission(Permission::Pos)->pluck('id')->all());
        $this->assertNotContains($barista->id, User::withPermission(Permission::Pos)->pluck('id'));
    }

    public function test_karyawan_tanpa_email_bisa_dibuat_untuk_kasir(): void
    {
        $user = User::factory()->create(['email' => null, 'password' => null, 'pin' => '5555']);

        $this->assertNull($user->fresh()->email);
    }

    public function test_label_hak_dari_file_terjemahan(): void
    {
        $this->assertSame('Pakai aplikasi kasir', Permission::Pos->label());
        $this->assertCount(count(Permission::cases()), Permission::options());
    }
}
