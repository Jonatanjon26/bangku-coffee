<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\ReasonType;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\DiscountPreset;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\OrderType;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DeviceSeeder;
use Database\Seeders\StaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Data awal harus sama dengan mockup (docs/mockup/shared/config.js). */
class SeedDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.email' => 'owner@bangkucoffee.id', 'admin.name' => 'Owner', 'admin.password' => 'OwnerContoh2026']);
    }

    public function test_konfigurasi_sama_dengan_mockup(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(18, MenuItem::count());
        $this->assertSame(['Kopi', 'Non-kopi', 'Teh', 'Makanan', 'Camilan'], Category::orderBy('sort')->pluck('name')->all());
        $this->assertSame('Dapur', Category::firstWhere('name', 'Makanan')->station->name);
        $this->assertSame(['Ukuran', 'Suhu', 'Gula', 'Tambahan'], ModifierGroup::orderBy('sort')->pluck('name')->all());
        $this->assertTrue(ModifierGroup::firstWhere('name', 'Suhu')->print_always);
        $this->assertSame(['Regular' => 0, 'Large' => 5000], ModifierGroup::firstWhere('name', 'Ukuran')->options->pluck('price_delta', 'name')->all());
        $this->assertSame('Regular', ModifierGroup::firstWhere('name', 'Ukuran')->options->firstWhere('is_default', true)->name);

        $kopiSusu = MenuItem::firstWhere('name', 'Kopi Susu Bangku');
        $this->assertSame(22000, $kopiSusu->price);
        $this->assertTrue($kopiSusu->is_best_seller);
        $this->assertSame(['Ukuran', 'Suhu', 'Gula', 'Tambahan'], $kopiSusu->modifierGroups->pluck('name')->all());
        $this->assertTrue(MenuItem::firstWhere('name', 'Nasi Goreng Bangku')->is_sold_out);
        $this->assertSame(['Gula'], MenuItem::firstWhere('name', 'Kopi Tubruk')->modifierGroups->pluck('name')->all());

        $this->assertSame(['Di tempat', 'Bawa pulang'], OrderType::active()->pluck('name')->all());
        $this->assertTrue(OrderType::firstWhere('name', 'Di tempat')->applies_service);
        $this->assertSame(['Tunai', 'QRIS'], PaymentMethod::active()->pluck('name')->all());
        $this->assertNull(PaymentMethod::firstWhere('name', 'Debit'));
        $this->assertSame(8, Bank::count());
        $this->assertSame(5, DiscountPreset::count());
        $this->assertSame([100000, 50000, 20000, 10000, 5000, 2000, 1000], Denomination::orderBy('sort')->pluck('value')->all());
        $this->assertSame(['Pagi', 'Sore'], ShiftTemplate::active()->pluck('name')->all());
        $this->assertSame(6, Reason::ofType(ReasonType::CashOut)->count());
        $this->assertSame(4, Reason::ofType(ReasonType::Void)->count());
        $this->assertTrue(Reason::ofType(ReasonType::CashOut)->firstWhere('label', 'Lainnya')->requires_note);

        $settings = app(Settings::class);
        $this->assertSame('Bangku Coffee', $settings->get('store.name'));
        $this->assertSame(20000, $settings->get('policy.variance_approval_above'));
        $this->assertSame(32, $settings->get('printing.cols'));
        $this->assertSame(count(config('pos.settings', []), COUNT_RECURSIVE) - count(config('pos.settings')), Setting::count());
    }

    public function test_peran_dan_hak_sama_dengan_mockup(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(Permission::cases()), count(Role::firstWhere('name', 'Owner')->permissions()));
        $manager = Role::firstWhere('name', 'Kepala toko');
        $this->assertTrue($manager->hasPermission(Permission::Approve));
        $this->assertFalse($manager->hasPermission(Permission::Settings));
        $this->assertSame([Permission::Pos], Role::firstWhere('name', 'Kasir')->permissions());
        $this->assertSame([], Role::firstWhere('name', 'Barista')->permissions());
    }

    public function test_karyawan_contoh_dengan_pin_terhash(): void
    {
        $this->seed(DatabaseSeeder::class);

        $users = User::with('role')->get()->keyBy('name');
        $this->assertSame(['Owner', 'Rani', 'Dimas', 'Sinta'], $users->keys()->all());
        $this->assertSame('Owner', $users['Owner']->role->name);
        $this->assertTrue($users['Owner']->is_admin);
        $this->assertSame('Kasir', $users['Rani']->role->name);
        $this->assertNull($users['Rani']->email);
        $this->assertSame('sinta@bangkucoffee.id', $users['Sinta']->email);

        foreach (['Rani' => '1111', 'Dimas' => '2222', 'Sinta' => '3333', 'Owner' => '9999'] as $name => $pin) {
            $this->assertNotSame($pin, $users[$name]->pin);
            $this->assertTrue($users[$name]->checkPin($pin));
        }

        $this->assertTrue(auth()->validate(['email' => 'sinta@bangkucoffee.id', 'password' => 'BangkuSinta2026']));
    }

    public function test_perangkat_dan_kode_pendaftaran_contoh(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Device::where('name', 'Tablet Kasir 1')->exists());
        $code = DevicePairingCode::findUsable(DeviceSeeder::PAIRING_CODE);
        $this->assertSame('Tablet Kasir 2', $code->device_name);
        $this->assertNotSame(DeviceSeeder::PAIRING_CODE, $code->code_hash);
    }

    public function test_seeding_ulang_tidak_menggandakan_dan_tidak_menimpa_perubahan(): void
    {
        $this->seed(DatabaseSeeder::class);
        MenuItem::firstWhere('name', 'Americano')->update(['price' => 21000]);
        app(Settings::class)->set('store.name', 'Bangku Coffee Medan');
        User::firstWhere('name', 'Rani')->update(['pin' => '4321']);
        $counts = [MenuItem::count(), Role::count(), User::count(), Reason::count(), Setting::count(), Device::count(), DevicePairingCode::count()];

        app(Settings::class)->flush();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, [MenuItem::count(), Role::count(), User::count(), Reason::count(), Setting::count(), Device::count(), DevicePairingCode::count()]);
        $this->assertSame(21000, MenuItem::firstWhere('name', 'Americano')->price);
        $this->assertSame('Bangku Coffee Medan', app(Settings::class)->get('store.name'));
        $this->assertTrue(User::firstWhere('name', 'Rani')->checkPin('4321'));
    }

    public function test_production_tanpa_karyawan_pin_dan_perangkat_contoh(): void
    {
        $this->app['env'] = 'production';

        // db:seed di production butuh --force (tanpa itu seeder tidak jalan sama sekali).
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(['Owner'], User::pluck('name')->all());
        $owner = User::first();
        $this->assertSame('Owner', $owner->role->name);
        $this->assertNull($owner->pin);
        $this->assertSame(0, Device::count());
        $this->assertSame(0, DevicePairingCode::count());
        $this->assertSame(18, MenuItem::count());
    }

    public function test_staff_seeder_tanpa_admin_email_tetap_jalan(): void
    {
        config(['admin.email' => null]);
        $this->seed(DatabaseSeeder::class);
        $this->seed(StaffSeeder::class);

        $this->assertSame(['Rani', 'Dimas', 'Sinta'], User::pluck('name')->all());
    }
}
