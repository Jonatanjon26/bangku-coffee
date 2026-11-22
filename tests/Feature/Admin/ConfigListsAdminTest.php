<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentKind;
use App\Filament\Clusters\Settings\Resources\Devices\Pages\ManageDevices;
use App\Filament\Clusters\Settings\Resources\DiscountPresets\Pages\ManageDiscountPresets;
use App\Filament\Clusters\Settings\Resources\OrderTypes\Pages\ManageOrderTypes;
use App\Filament\Clusters\Settings\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Filament\Clusters\Settings\Resources\Reasons\Pages\ManageReasons;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\OrderType;
use App\Models\PaymentMethod;
use Database\Seeders\DemoSeeder;
use Filament\Actions\CreateAction;
use Livewire\Livewire;

class ConfigListsAdminTest extends AdminTestCase
{
    public function test_jenis_pesanan_aktif_minimal_satu(): void
    {
        $this->actingAs($this->owner());
        $take = OrderType::firstWhere('name', 'Bawa pulang');
        $take->update(['is_active' => false]);
        $dine = OrderType::firstWhere('name', 'Di tempat');

        Livewire::test(ManageOrderTypes::class)
            ->callTableAction('edit', $dine, data: ['is_active' => false])
            ->assertHasTableActionErrors(['is_active']);

        Livewire::test(ManageOrderTypes::class)->assertTableActionHidden('delete', $dine);

        $this->assertTrue($dine->fresh()->is_active);
    }

    public function test_perubahan_jenis_pesanan_tercatat_di_audit(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(ManageOrderTypes::class)
            ->callTableAction('edit', OrderType::firstWhere('name', 'Ojek online'), data: ['is_active' => true])
            ->assertHasNoTableActionErrors();

        $log = AuditLog::firstWhere('event', 'order_type.updated');
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame(['is_active' => true], $log->new_values);
    }

    public function test_hanya_satu_laci_tunai_aktif(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManagePaymentMethods::class)
            ->callAction(CreateAction::class, data: ['name' => 'Tunai 2', 'kind' => PaymentKind::Cash->value, 'is_active' => true])
            ->assertHasActionErrors(['is_active']);

        Livewire::test(ManagePaymentMethods::class)
            ->callAction(CreateAction::class, data: ['name' => 'GoPay', 'kind' => PaymentKind::Edc->value, 'is_active' => true])
            ->assertHasNoActionErrors();
    }

    public function test_metode_bayar_yang_sudah_dipakai_tidak_bisa_dihapus(): void
    {
        $this->actingAs($this->owner());
        config(['admin.email' => null]);
        $this->seed(DemoSeeder::class);
        $cash = PaymentMethod::firstWhere('name', 'Tunai');
        $unused = PaymentMethod::firstWhere('name', 'Kartu kredit');

        Livewire::test(ManagePaymentMethods::class)
            ->assertTableActionHidden('delete', $cash)
            ->callTableAction('delete', $unused);

        $this->assertModelExists($cash);
        $this->assertModelMissing($unused);
    }

    public function test_alasan_unik_per_daftar(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManageReasons::class)
            ->callAction(CreateAction::class, data: ['type' => 'cash_out', 'label' => 'Galon'])
            ->assertHasActionErrors(['label']);

        Livewire::test(ManageReasons::class)
            ->callAction(CreateAction::class, data: ['type' => 'cash_in', 'label' => 'Galon'])
            ->assertHasNoActionErrors();
    }

    public function test_diskon_persen_maksimal_100(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManageDiscountPresets::class)
            ->callAction(CreateAction::class, data: ['type' => 'percent', 'value' => 150])
            ->assertHasActionErrors(['value']);
    }

    public function test_kode_pendaftaran_baru_membatalkan_kode_lama_dan_ditampilkan_sekali(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        [, $oldCode] = DevicePairingCode::generate('Tablet Lama', now()->addDay());

        Livewire::test(ManageDevices::class)
            ->callAction('pair', data: ['device_name' => 'Tablet Kasir 3'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertNull(DevicePairingCode::findUsable($oldCode));
        $row = DevicePairingCode::firstWhere('device_name', 'Tablet Kasir 3');
        $this->assertSame($owner->id, $row->created_by);
        $this->assertTrue($row->expires_at->between(now()->addHours(23), now()->addHours(25)));
    }

    public function test_cabut_tablet(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        [$device, $token] = Device::register('Tablet Kasir 1');

        Livewire::test(ManageDevices::class)->callTableAction('revoke', $device);

        $this->assertTrue($device->fresh()->isRevoked());
        $this->assertSame($owner->id, $device->fresh()->revoked_by);
        $this->assertNull(Device::findByToken($token));
        $this->assertTrue(AuditLog::where('event', 'device.updated')->where('auditable_id', $device->id)->exists());
    }

    public function test_tanpa_hak_pengaturan_tidak_bisa_membuat_kode(): void
    {
        $this->actingAs($this->staff('Kepala toko'));

        Livewire::test(ManageDevices::class)->assertForbidden();
        $this->assertSame(0, DevicePairingCode::count());
    }
}
