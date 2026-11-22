<?php

namespace Tests\Feature\Pos;

use App\Enums\ReasonType;
use App\Livewire\Pos\Kasir;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Support\Customers;
use App\Support\OrderCorrections;
use App\Support\ShiftService;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/** Pelanggan di kasir: saran nama/no. HP, simpan pelanggan baru, kunjungan & poin dihitung di server. */
class CustomerKasirTest extends PosTestCase
{
    private Device $device;

    private string $token;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00'));
        [$this->device, $this->token] = $this->tablet();
        $this->shift = app(ShiftService::class)->open($this->user('Rani'), $this->device, ShiftTemplate::firstWhere('name', 'Pagi')->id, [100000 => 3], 0, null);
        Customer::create(['name' => 'Ana Lestari', 'phone' => '081234567890', 'notes' => 'Alergi susu sapi']);
        Customer::create(['name' => 'Budi', 'phone' => '085700001111']);
    }

    private function kasir()
    {
        $this->signIn($this->user('Rani'), $this->device, $this->token);

        return Livewire::withCookies([$this->cookieName() => $this->token])->test(Kasir::class);
    }

    private function americano($kasir)
    {
        return $kasir->call('addLine', MenuItem::firstWhere('name', 'Americano')->id); // Rp 20.000
    }

    public function test_saran_dari_nama_atau_nomor_hp(): void
    {
        $kasir = $this->kasir();

        $this->assertSame(['Ana Lestari'], array_column($kasir->instance()->searchCustomers(app(Customers::class), 'les'), 'name'));
        // No. HP hanya cocok bila lengkap (format apa pun); sebagian tidak (digit tersamar tidak bisa ditebak).
        $this->assertSame(['Budi'], array_column($kasir->instance()->searchCustomers(app(Customers::class), '+62 857-0000-1111'), 'name'));
        $this->assertSame([], $kasir->instance()->searchCustomers(app(Customers::class), '0857000'));
        $this->assertSame([], $kasir->instance()->searchCustomers(app(Customers::class), '08570000111'));
        $this->assertSame([], $kasir->instance()->searchCustomers(app(Customers::class), 'a'));
        // Karakter LIKE tidak dianggap wildcard.
        $this->assertSame([], $kasir->instance()->searchCustomers(app(Customers::class), '%%'));

        $this->setting('customers.enabled', false);
        $this->assertSame([], $kasir->instance()->searchCustomers(app(Customers::class), 'ana'));
    }

    public function test_saran_tidak_mengirim_catatan_dan_pencarian_dibatasi(): void
    {
        config(['pos.customer_lookups_per_minute' => 5]);
        $kasir = $this->kasir();
        $search = fn (string $q) => $kasir->instance()->searchCustomers(app(Customers::class), $q);

        $first = $search('ana');
        $this->assertSame(['id', 'name', 'phone', 'visits'], array_keys($first[0]));
        $this->assertSame('0812*****890', $first[0]['phone']); // no. HP lengkap hanya di admin

        foreach (range(2, 5) as $i) {
            $search('ana');
        }
        $this->assertSame([], $search('ana'));

        // Memilih pelanggan lewat id juga dihitung (tidak bisa membaca semua pelanggan satu per satu).
        $kasir->call('linkCustomer', Customer::first()->id)->assertSet('order.customer_id', null)
            ->assertDispatched('toast', message: __('pos.customers.errors.too_many'));
    }

    public function test_simpan_pelanggan_ikut_batas_dan_batas_harian(): void
    {
        config(['pos.customer_lookups_per_minute' => 100, 'pos.customer_lookups_per_day' => 3]);
        $kasir = $this->kasir();

        foreach (range(1, 3) as $i) {
            $kasir->call('registerCustomer', 'Uji '.$i)->assertDispatched('customer-saved');
        }
        // Batas harian tercapai: simpan (termasuk no. HP terdaftar yang menampilkan pelanggan) & cari ditolak.
        $kasir->call('registerCustomer', 'Ana L', '081234567890')->assertDispatched('customer-failed', message: __('pos.customers.errors.too_many'))
            ->assertDontSee('Ana Lestari');
        $this->assertNotSame(Customer::firstWhere('name', 'Ana Lestari')->id, $kasir->get('order')['customer_id']);
        $this->assertSame([], $kasir->instance()->searchCustomers(app(Customers::class), 'ana'));
    }

    public function test_pilih_pelanggan_mengisi_nama_dan_tag(): void
    {
        $ana = Customer::firstWhere('name', 'Ana Lestari');

        $this->kasir()->call('linkCustomer', $ana->id)
            ->assertSet('customerName', 'Ana Lestari')
            ->assertSet('order.customer_id', $ana->id)
            ->assertSee('Ana Lestari · 0812*****890 · 0× kunjungan · Alergi susu sapi')->assertDontSee('081234567890')
            ->call('unlinkCustomer')
            ->assertSet('order.customer_id', null)
            ->assertSet('customerName', 'Ana Lestari');
    }

    public function test_nama_diubah_melepas_pelanggan(): void
    {
        $ana = Customer::firstWhere('name', 'Ana Lestari');

        $this->kasir()->call('linkCustomer', $ana->id)->set('customerName', 'Ana Lestari')->assertSet('order.customer_id', $ana->id)
            ->set('customerName', 'Andi')->assertSet('order.customer_id', null);
    }

    public function test_id_pelanggan_tidak_bisa_diubah_dari_browser(): void
    {
        $kasir = $this->kasir();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $kasir->set('order.customer_id', Customer::first()->id);
    }

    public function test_simpan_pelanggan_baru_dari_kasir(): void
    {
        $kasir = $this->kasir()->call('registerCustomer', 'Citra', '+62 813-1111-2222', 'tanpa gula', true)
            ->assertDispatched('customer-saved')->assertSet('customerName', 'Citra');

        $citra = Customer::firstWhere('name', 'Citra');
        $this->assertSame('081311112222', $citra->phone);
        $this->assertTrue($citra->contact_consent);
        $this->assertSame('tanpa gula', $citra->notes);
        $kasir->assertSet('order.customer_id', $citra->id);
        $this->assertTrue(AuditLog::where('event', 'customer.created')->where('user_id', $this->user('Rani')->id)->exists());
        $this->assertStringNotContainsString('Citra', json_encode(AuditLog::firstWhere('event', 'customer.created')->new_values));
    }

    public function test_nomor_hp_terdaftar_memakai_pelanggan_lama(): void
    {
        $ana = Customer::firstWhere('name', 'Ana Lestari');

        $this->kasir()->call('registerCustomer', 'Ana L', '0812 3456 7890')
            ->assertDispatched('toast', message: __('pos.customers.existing', ['name' => 'Ana Lestari']))
            ->assertSet('order.customer_id', $ana->id)->assertSet('customerName', 'Ana Lestari');
        $this->assertSame(2, Customer::count());
    }

    public function test_data_pelanggan_divalidasi_di_server(): void
    {
        $this->kasir()
            ->call('registerCustomer', '', '')->assertDispatched('customer-failed')
            ->call('registerCustomer', 'Dedi', '12345')->assertDispatched('customer-failed', message: __('pos.customers.errors.phone'))
            ->call('registerCustomer', 'Dedi', '0813'.str_repeat(' ', 20).'11112222')->assertDispatched('customer-failed', message: __('pos.customers.errors.phone'))
            ->call('registerCustomer', str_repeat('x', 25))->assertDispatched('customer-failed')
            ->call('registerCustomer', 'Dedi', '', str_repeat('x', 61))->assertDispatched('customer-failed');
        $this->assertSame(2, Customer::count());

        $this->setting('customers.ask_phone', false);
        $this->kasir()->call('registerCustomer', 'Eka', '081399998888')->assertDispatched('customer-saved');
        $this->assertNull(Customer::firstWhere('name', 'Eka')->phone);

        $this->setting('customers.enabled', false);
        $this->kasir()->call('registerCustomer', 'Fajar')->assertDispatched('customer-failed', message: __('pos.customers.errors.disabled'));
        $this->assertNull(Customer::firstWhere('name', 'Fajar'));
    }

    public function test_bayar_mencatat_kunjungan_belanja_dan_poin(): void
    {
        $this->setting('customers.loyalty_enabled', true);
        $this->setting('customers.point_per', 10000);
        $ana = Customer::firstWhere('name', 'Ana Lestari');

        $this->americano($this->kasir())->call('linkCustomer', $ana->id)->call('pay', PaymentMethod::firstWhere('name', 'QRIS')->id)->assertDispatched('paid');

        $order = Order::latest('id')->first();
        $this->assertSame($ana->id, $order->customer_id);
        $this->assertSame(2, $order->points_earned);
        $ana->refresh();
        $this->assertSame([1, 20000, 2], [$ana->visits_count, $ana->total_spent, $ana->points]);
        $this->assertEquals(now(), $ana->last_visit_at);

        // Poin tampil di tag hanya bila program poin aktif.
        $this->kasir()->call('linkCustomer', $ana->id)->assertSee('1× kunjungan · 2 poin');
        $this->setting('customers.loyalty_enabled', false);
        $this->kasir()->call('linkCustomer', $ana->id)->assertDontSee('2 poin');
    }

    public function test_refund_mengurangi_kunjungan_belanja_dan_poin(): void
    {
        $this->setting('customers.loyalty_enabled', true);
        $ana = Customer::firstWhere('name', 'Ana Lestari');
        $this->americano($this->kasir())->call('linkCustomer', $ana->id)->call('pay', PaymentMethod::firstWhere('name', 'QRIS')->id);
        // Aturan poin berubah setelah transaksi: yang dikurangi tetap poin yang didapat.
        $this->setting('customers.point_per', 5000);

        $reason = Reason::ofType(ReasonType::Refund)->first()->id;
        app(OrderCorrections::class)->refund(Order::latest('id')->first(), $this->user('Rani'), $this->user('Sinta'), $reason, null, $this->shift);

        $ana->refresh();
        $this->assertSame([0, 0, 0], [$ana->visits_count, $ana->total_spent, $ana->points]);
    }

    public function test_open_bill_menyimpan_pelanggan_dan_dihitung_sekali_saat_dibayar(): void
    {
        $budi = Customer::firstWhere('name', 'Budi');
        $this->americano($this->kasir())->call('linkCustomer', $budi->id)->call('hold');

        $order = Order::latest('id')->first();
        $this->assertSame($budi->id, $order->customer_id);
        $this->assertSame(0, $budi->fresh()->visits_count);

        $kasir = $this->kasir()->call('openHeld', $order->id)->assertSet('order.customer_id', $budi->id)->assertSet('customerName', 'Budi');
        $this->americano($kasir)->call('pay', PaymentMethod::firstWhere('name', 'QRIS')->id)->assertDispatched('paid');

        $budi->refresh();
        $this->assertSame([1, 40000], [$budi->visits_count, $budi->total_spent]);
    }

    public function test_pelanggan_dihapus_saat_pesanan_disusun(): void
    {
        $ana = Customer::firstWhere('name', 'Ana Lestari');
        $kasir = $this->americano($this->kasir())->call('linkCustomer', $ana->id);
        $ana->erase();

        $kasir->call('pay', PaymentMethod::firstWhere('name', 'QRIS')->id)->assertDispatched('paid');
        $this->assertNull(Order::latest('id')->first()->customer_id);
    }

    public function test_database_pelanggan_mati_tidak_menyambungkan(): void
    {
        $this->setting('customers.enabled', false);
        $this->kasir()->call('linkCustomer', Customer::first()->id)->assertSet('order.customer_id', null)->assertDontSeeHtml('id="cust-btn"');
    }

    public function test_data_pelanggan_di_layar_di_escape(): void
    {
        $evil = Customer::create(['name' => '<img src=x onerror=alert(1)>', 'notes' => '<script>alert(2)</script>']);

        $this->kasir()->call('linkCustomer', $evil->id)
            ->assertDontSeeHtml('<img src=x onerror=alert(1)>')
            ->assertDontSeeHtml('<script>alert(2)</script>')
            ->assertSeeHtml('&lt;script&gt;alert(2)&lt;/script&gt;');
    }
}
