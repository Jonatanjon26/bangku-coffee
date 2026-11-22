<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Filament\Resources\Customers\Pages\ManageCustomers;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Admin › Pelanggan: daftar & cari, tambah/ubah dengan validasi server, riwayat, hapus data atas permintaan, CSV. */
class CustomersAdminTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 21:30'));
        config(['admin.email' => null]);
        $this->seed(DemoSeeder::class);
    }

    public function test_daftar_dan_cari_nama_atau_no_hp(): void
    {
        $customer = Customer::whereNotNull('phone')->first();
        $other = Customer::whereKeyNot($customer->id)->first();
        $this->actingAs($this->staff('Kepala toko'));

        Livewire::test(ManageCustomers::class)
            ->assertCountTableRecords(Customer::count())
            ->assertSee(__('admin.customers.count', ['count' => Customer::count(), 'consent' => Customer::where('contact_consent', true)->count()]))
            ->searchTable('+62'.substr($customer->phone, 1))
            ->assertCanSeeTableRecords([$customer])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_kolom_poin_hanya_bila_program_poin_aktif(): void
    {
        $this->actingAs($this->owner());
        Livewire::test(ManageCustomers::class)->assertTableColumnHidden('points');

        app(Settings::class)->set('customers.loyalty_enabled', true);
        Livewire::test(ManageCustomers::class)->assertTableColumnVisible('points')->assertSee(__('admin.customers.loyalty_on'));
    }

    public function test_tambah_pelanggan_divalidasi_dan_nomor_dinormalisasi(): void
    {
        $taken = Customer::whereNotNull('phone')->first();
        $this->actingAs($this->owner());

        Livewire::test(ManageCustomers::class)
            ->callAction('create', ['name' => 'Zahra Uji', 'phone' => '+62'.substr($taken->phone, 1)])
            ->assertHasActionErrors(['phone']);
        Livewire::test(ManageCustomers::class)
            ->callAction('create', ['name' => 'Zahra Uji', 'phone' => '0812'])
            ->assertHasActionErrors(['phone']);
        $this->assertNull(Customer::firstWhere('name', 'Zahra Uji'));

        Livewire::test(ManageCustomers::class)
            ->callAction('create', ['name' => 'Zahra Uji', 'phone' => '+62 813-2222-3333', 'notes' => 'oat milk', 'contact_consent' => true])
            ->assertHasNoActionErrors();

        $zahra = Customer::firstWhere('name', 'Zahra Uji');
        $this->assertSame('081322223333', $zahra->phone);
        $this->assertTrue($zahra->contact_consent);
    }

    public function test_pesan_validasi_form_dalam_bahasa_indonesia(): void
    {
        $this->actingAs($this->owner());

        $errors = Livewire::test(ManageCustomers::class)
            ->callAction('create', ['name' => 'Zahra Uji', 'phone' => str_repeat('0', 21)])
            ->assertHasActionErrors(['phone'])
            ->errors();

        $this->assertSame('No. HP tidak boleh lebih dari 20 karakter.', $errors->first('mountedActions.0.data.phone'));
    }

    public function test_ubah_pelanggan_boleh_memakai_nomornya_sendiri(): void
    {
        $customer = Customer::whereNotNull('phone')->first();
        $this->actingAs($this->owner());

        Livewire::test(ManageCustomers::class)
            ->callAction(TestAction::make('edit')->table($customer), ['name' => 'Nama Baru', 'phone' => $customer->phone])
            ->assertHasNoActionErrors();

        $this->assertSame('Nama Baru', $customer->fresh()->name);
    }

    public function test_riwayat_transaksi_pelanggan(): void
    {
        $customer = Customer::whereHas('orders', fn ($q) => $q->where('status', OrderStatus::Paid))->first();
        $last = $customer->orders()->where('status', OrderStatus::Paid)->latest('paid_at')->first();
        $this->actingAs($this->owner());

        Livewire::test(ManageCustomers::class)
            ->mountAction(TestAction::make('view')->table($customer))
            ->assertMountedActionModalSee($last->code);
    }

    public function test_hapus_data_atas_permintaan(): void
    {
        $customer = Customer::has('orders')->first();
        $orders = $customer->orders()->pluck('id');
        $this->actingAs($this->owner());

        Livewire::test(ManageCustomers::class)->callAction(TestAction::make('erase')->table($customer));

        $this->assertModelMissing($customer);
        $this->assertSame($orders->count(), Order::whereIn('id', $orders)->whereNull('customer_name')->count());
        $this->assertTrue(AuditLog::where('event', 'customer.erased')->exists());
    }

    public function test_tanpa_hak_pelanggan_ditolak(): void
    {
        $this->actingAs($this->staff('Barista'));
        $this->get(ManageCustomers::getUrl())->assertForbidden();

        $this->actingAs($this->owner());
        $page = Livewire::test(ManageCustomers::class)->instance();
        $this->actingAs($this->staff('Barista'));
        $this->expectException(HttpException::class);
        $page->downloadCsv();
    }

    public function test_unduh_csv_aman_dari_formula(): void
    {
        Customer::first()->update(['name' => '=HYPERLINK("http://x")', 'notes' => "\t=1+1"]);
        $this->actingAs($this->owner());

        $response = Livewire::test(ManageCustomers::class)->instance()->downloadCsv();
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Nama;"No. HP";Kunjungan;Belanja;Poin', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'\t=1+1", $csv);
        $this->assertTrue(AuditLog::where('event', 'customer.exported')->where('new_values->rows', Customer::count())->exists());
        $this->assertSame(Customer::count() + 1, substr_count(trim($csv), "\n") + 1);
    }
}
