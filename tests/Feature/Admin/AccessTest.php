<?php

namespace Tests\Feature\Admin;

use App\Filament\Clusters\Settings\Pages\ChargeSettings;
use App\Filament\Clusters\Settings\Pages\CustomerSettings;
use App\Filament\Clusters\Settings\Pages\PolicySettings;
use App\Filament\Clusters\Settings\Pages\StoreSettings;
use App\Filament\Clusters\Settings\Resources\Banks\BankResource;
use App\Filament\Clusters\Settings\Resources\Denominations\DenominationResource;
use App\Filament\Clusters\Settings\Resources\Devices\DeviceResource;
use App\Filament\Clusters\Settings\Resources\DiscountPresets\DiscountPresetResource;
use App\Filament\Clusters\Settings\Resources\OrderTypes\OrderTypeResource;
use App\Filament\Clusters\Settings\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Clusters\Settings\Resources\Reasons\ReasonResource;
use App\Filament\Clusters\Settings\Resources\ShiftTemplates\ShiftTemplateResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\MenuItems\MenuItemResource;
use App\Filament\Resources\ModifierGroups\ModifierGroupResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use PHPUnit\Framework\Attributes\DataProvider;

/** Hak akses halaman admin dicek di server (bukan hanya menu disembunyikan). */
class AccessTest extends AdminTestCase
{
    private const MENU = [MenuItemResource::class, CategoryResource::class, ModifierGroupResource::class];

    private const CUSTOMERS = [CustomerResource::class];

    private const STAFF = [UserResource::class, RoleResource::class];

    private const SETTINGS = [
        OrderTypeResource::class, PaymentMethodResource::class, BankResource::class, ShiftTemplateResource::class, DenominationResource::class,
        ReasonResource::class, DiscountPresetResource::class, DeviceResource::class,
        StoreSettings::class, ChargeSettings::class, PolicySettings::class, CustomerSettings::class,
    ];

    public static function pages(): array
    {
        $rows = [];

        foreach ([...self::MENU, ...self::CUSTOMERS, ...self::STAFF, ...self::SETTINGS] as $page) {
            $rows[class_basename($page)] = [$page];
        }

        return $rows;
    }

    private static function url(string $page): string
    {
        return str_ends_with($page, 'Resource') ? $page::getUrl('index') : $page::getUrl();
    }

    #[DataProvider('pages')]
    public function test_owner_bisa_membuka_semua_halaman(string $page): void
    {
        $this->actingAs($this->owner())->get(self::url($page))->assertOk();
    }

    #[DataProvider('pages')]
    public function test_kepala_toko_hanya_menu_dan_pelanggan(string $page): void
    {
        $response = $this->actingAs($this->staff('Kepala toko'))->get(self::url($page));

        in_array($page, [...self::MENU, ...self::CUSTOMERS], true) ? $response->assertOk() : $response->assertForbidden();
    }

    #[DataProvider('pages')]
    public function test_kasir_tidak_bisa_masuk_panel(string $page): void
    {
        $this->actingAs($this->staff('Kasir'))->get(self::url($page))->assertForbidden();
    }

    #[DataProvider('pages')]
    public function test_tamu_diarahkan_ke_login(string $page): void
    {
        $this->get(self::url($page))->assertRedirect();
    }

    public function test_menu_navigasi_hanya_yang_berhak(): void
    {
        $this->actingAs($this->staff('Kepala toko'))
            ->get(MenuItemResource::getUrl('index'))
            ->assertSee(__('admin.categories.plural'))
            ->assertDontSee(UserResource::getUrl('index'))
            ->assertDontSee(StoreSettings::getUrl());
    }
}
