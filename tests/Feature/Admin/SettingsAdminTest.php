<?php

namespace Tests\Feature\Admin;

use App\Filament\Clusters\Settings\Pages\ChargeSettings;
use App\Filament\Clusters\Settings\Pages\PolicySettings;
use App\Filament\Clusters\Settings\Pages\StoreSettings;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\AuditLog;
use App\Support\Settings;
use Livewire\Livewire;

class SettingsAdminTest extends AdminTestCase
{
    public function test_simpan_toko_dan_struk(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(StoreSettings::class)
            ->assertSet('data.store.name', 'Bangku Coffee')
            ->set('data.store.name', 'Bangku Coffee Medan')
            ->set('data.printing.cols', '48')
            ->set('data.printing.receipt_default', false)
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(Settings::class);
        $settings->flush();
        $this->assertSame('Bangku Coffee Medan', $settings->get('store.name'));
        $this->assertSame(48, $settings->get('printing.cols'));
        $this->assertFalse($settings->get('printing.receipt_default'));
        $this->assertSame(3, AuditLog::where('event', 'setting.updated')->where('user_id', $owner->id)->count());
    }

    public function test_validasi_rentang_nilai(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(StoreSettings::class)->set('data.printing.cols', '80')->call('save')->assertHasErrors(['data.printing.cols']);
        Livewire::test(ChargeSettings::class)->set('data.charges.tax_rate', '45')->call('save')->assertHasErrors(['data.charges.tax_rate']);
        Livewire::test(PolicySettings::class)->set('data.policy.max_pin_attempts', '1')->call('save')->assertHasErrors(['data.policy.max_pin_attempts']);

        $this->assertSame(32, app(Settings::class)->get('printing.cols'));
    }

    public function test_pajak_disimpan_sebagai_angka(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ChargeSettings::class)->set('data.charges.tax_enabled', true)->set('data.charges.tax_rate', '11')->call('save')->assertHasNoErrors();

        $this->assertTrue(app(Settings::class)->get('charges.tax_enabled'));
        $this->assertSame(11, app(Settings::class)->get('charges.tax_rate'));
    }

    public function test_ganti_panjang_pin_dari_4_ke_6_dengan_masa_peralihan(): void
    {
        // Kasus pemilik: semua karyawan sudah memakai PIN 4 digit, lalu ingin pindah ke 6 digit.
        $this->actingAs($this->owner());
        $rani = $this->staff('Kasir', ['name' => 'Rani', 'pin' => '1111']);

        // 1. Pengaturan boleh diubah walau masih ada PIN 4 digit (dulu ditolak → buntu).
        Livewire::test(PolicySettings::class)->set('data.policy.pin_length', 6)->call('save')->assertHasNoErrors();
        $this->assertSame(6, app(Settings::class)->get('policy.pin_length'));

        // 2. Yang belum ganti PIN ditandai di Pengaturan & daftar Karyawan.
        $this->assertSame(['Rani'], PolicySettings::staffWithOtherPinLength(6));
        Livewire::test(PolicySettings::class)->assertSee('Belum memakai PIN dengan panjang ini');
        Livewire::test(ManageUsers::class)->assertSee(__('admin.users.pin_old', ['length' => 4]));

        // 3. PIN baru harus 6 digit (4 digit ditolak), lalu tanda hilang.
        Livewire::test(ManageUsers::class)->callTableAction('edit', $rani, ['pin' => '2468'])->assertHasTableActionErrors(['pin']);
        Livewire::test(ManageUsers::class)->callTableAction('edit', $rani, ['pin' => '246810'])->assertHasNoTableActionErrors();
        $this->assertSame(6, $rani->fresh()->pin_length);
        $this->assertSame([], PolicySettings::staffWithOtherPinLength(6));
        Livewire::test(ManageUsers::class)->assertDontSee(__('admin.users.pin_old', ['length' => 4]));
    }

    public function test_panjang_pin_boleh_diubah_bila_semua_pin_sudah_sesuai(): void
    {
        $this->actingAs($this->owner());
        $this->staff('Kasir', ['name' => 'Rani', 'pin' => '111111']);
        $this->staff('Barista', ['name' => 'Tono', 'pin' => '2222']); // bukan pemakai kasir

        Livewire::test(PolicySettings::class)->set('data.policy.pin_length', 6)->call('save')->assertHasNoErrors();
        $this->assertSame(6, app(Settings::class)->get('policy.pin_length'));
    }

    public function test_tanpa_hak_pengaturan_tidak_bisa_menyimpan(): void
    {
        $this->actingAs($this->staff('Kepala toko'));

        Livewire::test(StoreSettings::class)->assertForbidden();
        $this->assertSame('Bangku Coffee', app(Settings::class)->get('store.name'));
    }
}
