<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\MenuItems\MenuItemResource;
use App\Filament\Resources\MenuItems\Pages\ManageMenuItems;
use App\Filament\Resources\ModifierGroups\Pages\ManageModifierGroups;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use Filament\Actions\CreateAction;
use Livewire\Livewire;

class MenuAdminTest extends AdminTestCase
{
    public function test_tambah_menu_dengan_grup_varian(): void
    {
        $this->actingAs($this->staff('Kepala toko'));
        $kopi = Category::firstWhere('name', 'Kopi');
        $groups = ModifierGroup::whereIn('name', ['Ukuran', 'Suhu'])->pluck('id')->all();

        Livewire::test(ManageMenuItems::class)
            ->callAction(CreateAction::class, data: [
                'name' => 'Kopi Pandan',
                'category_id' => $kopi->id,
                'price' => 24000,
                'icon' => '🍵',
                'modifierGroups' => $groups,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $item = MenuItem::firstWhere('name', 'Kopi Pandan');
        $this->assertSame(24000, $item->price);
        $this->assertSame(['Ukuran', 'Suhu'], $item->modifierGroups->pluck('name')->all());
    }

    public function test_ikon_dipilih_dari_daftar(): void
    {
        $this->actingAs($this->owner());
        $item = MenuItem::firstWhere('name', 'Americano');

        // Daftar berisi emoji + nama (bisa dicari), termasuk semua ikon menu contoh.
        $options = MenuItemResource::iconOptions();
        $this->assertSame('🧋  es kopi / boba', $options['🧋']);
        $this->assertEmpty(MenuItem::pluck('icon')->unique()->reject(fn ($icon) => isset($options[$icon]))->all());

        // Di luar daftar ditolak; dari daftar diterima.
        Livewire::test(ManageMenuItems::class)->callTableAction('edit', $item, data: ['icon' => '<b>x</b>'])->assertHasTableActionErrors(['icon']);
        Livewire::test(ManageMenuItems::class)->callTableAction('edit', $item, data: ['icon' => '🧋'])->assertHasNoTableActionErrors();
        $this->assertSame('🧋', $item->fresh()->icon);
    }

    public function test_ikon_lama_di_luar_daftar_tetap_tersimpan_saat_diedit(): void
    {
        $this->actingAs($this->owner());
        $item = MenuItem::firstWhere('name', 'Americano');
        $item->update(['icon' => '🌿']);

        $this->assertArrayHasKey('🌿', MenuItemResource::iconOptions('🌿'));
        Livewire::test(ManageMenuItems::class)->callTableAction('edit', $item, data: ['price' => 21000])->assertHasNoTableActionErrors();
        $this->assertSame('🌿', $item->fresh()->icon);
    }

    public function test_harga_harus_rupiah_bulat_tidak_negatif(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManageMenuItems::class)
            ->callAction(CreateAction::class, data: ['name' => 'Salah', 'category_id' => Category::first()->id, 'price' => -5])
            ->assertHasActionErrors(['price']);
    }

    public function test_ubah_harga_tercatat_di_audit_dengan_pengguna(): void
    {
        $manager = $this->staff('Kepala toko');
        $this->actingAs($manager);
        $item = MenuItem::firstWhere('name', 'Americano');

        Livewire::test(ManageMenuItems::class)
            ->callTableAction('edit', $item, data: ['price' => 21000])
            ->assertHasNoTableActionErrors();

        $log = AuditLog::firstWhere('event', 'menu_item.price_changed');
        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame(['price' => 21000], $log->new_values);
    }

    public function test_tandai_habis_dari_tabel(): void
    {
        $this->actingAs($this->owner());
        $item = MenuItem::firstWhere('name', 'Croissant Butter');

        Livewire::test(ManageMenuItems::class)->call('updateTableColumnState', 'is_sold_out', (string) $item->id, true);

        $this->assertTrue($item->fresh()->is_sold_out);
    }

    public function test_varian_wajib_tepat_satu_pilihan_bawaan(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(ManageModifierGroups::class)
            ->callAction(CreateAction::class, data: [
                'name' => 'Es batu',
                'options' => [
                    ['name' => 'Normal', 'price_delta' => 0, 'is_default' => false, 'is_active' => true],
                    ['name' => 'Sedikit', 'price_delta' => 0, 'is_default' => false, 'is_active' => true],
                ],
            ])
            ->assertHasActionErrors(['options']);

        Livewire::test(ManageModifierGroups::class)
            ->callAction(CreateAction::class, data: [
                'name' => 'Es batu',
                'options' => [
                    ['name' => 'Normal', 'price_delta' => 0, 'is_default' => true, 'is_active' => true],
                    ['name' => 'Sedikit', 'price_delta' => 0, 'is_default' => false, 'is_active' => true],
                ],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(['Normal', 'Sedikit'], ModifierGroup::firstWhere('name', 'Es batu')->options->pluck('name')->all());
    }

    public function test_kategori_yang_masih_punya_menu_tidak_bisa_dihapus(): void
    {
        $this->actingAs($this->owner());
        $used = Category::firstWhere('name', 'Kopi');
        $empty = Category::create(['name' => 'Musiman', 'station_id' => $used->station_id]);

        Livewire::test(ManageCategories::class)
            ->assertTableActionHidden('delete', $used)
            ->callTableAction('delete', $empty);

        $this->assertModelExists($used);
        $this->assertModelMissing($empty);
    }

    public function test_tanpa_hak_menu_tidak_bisa_menjalankan_aksi(): void
    {
        $this->actingAs($this->staff('Kasir'));

        Livewire::test(ManageMenuItems::class)->assertForbidden();
    }

    public function test_nama_menu_di_escape(): void
    {
        $item = MenuItem::first();
        $item->update(['name' => '<script>alert("x")</script>']);

        $this->actingAs($this->owner())
            ->get(MenuItemResource::getUrl('index'))
            ->assertOk()
            ->assertDontSee('<script>alert("x")</script>', escape: false)
            ->assertSee('&lt;script&gt;', escape: false);
    }
}
