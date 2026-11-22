<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Livewire\Pos\Kasir;
use App\Models\Bank;
use App\Models\Device;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Support\ShiftService;
use Livewire\Livewire;

class CheckoutTest extends PosTestCase
{
    private Device $device;

    private string $token;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->device, $this->token] = $this->tablet();
        $this->shift = app(ShiftService::class)->open($this->user('Rani'), $this->device, ShiftTemplate::first()->id, [100000 => 3], 0, null);
    }

    private function kasir(string $name = 'Rani')
    {
        $this->signIn($this->user($name), $this->device, $this->token);

        return Livewire::withCookies([$this->cookieName() => $this->token])->test(Kasir::class);
    }

    private function id(string $item): int
    {
        return MenuItem::firstWhere('name', $item)->id;
    }

    private function method(string $name): int
    {
        return PaymentMethod::firstWhere('name', $name)->id;
    }

    public function test_bayar_tunai_menyimpan_pesanan_item_varian_dan_pembayaran(): void
    {
        $size = ModifierGroup::firstWhere('name', 'Ukuran');
        $large = $size->options()->where('name', 'Large')->value('id');

        $this->kasir()
            ->call('addLine', $this->id('Kopi Susu Bangku'), [$size->id => $large], 2, 'es sedikit')
            ->call('addLine', $this->id('Croissant Butter'))
            ->set('customerName', 'Ana')
            ->call('setTable', '7')
            ->call('pay', $this->method('Tunai'), null, 100000, false)
            ->assertDispatched('paid', cash: true, change: 26000, received: 100000, total: 74000)
            ->assertSet('customerName', '')
            ->assertSet('order.lines', []);

        $order = Order::with(['items.modifiers', 'payments'])->sole();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('B-'.now()->format('md').'-001', $order->code);
        $this->assertSame(1, $order->queue_number);
        $this->assertSame('Ana', $order->customer_name);
        $this->assertSame('7', $order->table_number);
        $this->assertSame('Di tempat', $order->order_type_name);
        $this->assertSame([74000, 0, 74000], [$order->subtotal, $order->discount_amount, $order->total]);
        $this->assertFalse($order->receipt_printed);
        $this->assertSame($this->shift->id, $order->paid_shift_id);
        $this->assertSame($this->shift->id, $order->opened_shift_id);
        $this->assertSame($this->user('Rani')->id, $order->paid_by);
        $this->assertSame($this->device->id, $order->device_id);
        $this->assertSame(now()->toDateString(), $order->paid_business_date->toDateString());

        $kopi = $order->items->firstWhere('name', 'Kopi Susu Bangku');
        $this->assertSame([22000, 27000, 2, 'es sedikit', 'Bar'], [$kopi->base_price, $kopi->unit_price, $kopi->quantity, $kopi->note, $kopi->station_name]);
        $this->assertSame(['Large', 'Es', 'Normal', 'Tanpa'], $kopi->modifiers->pluck('option_name')->all());
        $this->assertTrue($kopi->modifiers->firstWhere('group_name', 'Suhu')->print_always);

        $payment = $order->payments->sole();
        $this->assertSame([PaymentKind::Cash, 74000, 100000, 26000, $this->shift->id], [$payment->method_kind, $payment->amount, $payment->received, $payment->change, $payment->shift_id]);
        $this->assertNull($payment->bank_name);

        // Masuk ke ringkasan kas shift.
        $this->assertSame(300000 + 74000, $this->shift->fresh()->summarize()['expected']);
    }

    public function test_nomor_antrean_berurutan_dan_kode_tetap(): void
    {
        foreach (['Ana', 'Budi'] as $name) {
            $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', $name)->call('pay', $this->method('QRIS'));
        }

        $this->assertSame([1, 2], Order::orderBy('id')->pluck('queue_number')->all());
        $this->assertSame('B-'.now()->format('md').'-002', Order::latest('id')->value('code'));
    }

    public function test_edc_wajib_bank_dan_nominal_sama_dengan_total(): void
    {
        PaymentMethod::where('name', 'Kartu kredit')->update(['is_active' => true]);
        $kasir = $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Cici');

        $kasir->call('pay', $this->method('Kartu kredit'), null)->assertDispatched('pay-failed', message: __('pos.pay.errors.bank'));
        $this->assertSame(0, Order::count());

        $bca = Bank::firstWhere('name', 'BCA');
        $kasir->call('pay', $this->method('Kartu kredit'), $bca->id, 5)->assertDispatched('paid', cash: false);

        $payment = Order::sole()->payments->sole();
        $this->assertSame(['Kartu kredit', 'BCA', 20000, 20000, 0], [$payment->method_name, $payment->bank_name, $payment->amount, $payment->received, $payment->change]);
    }

    public function test_uang_tunai_kurang_ditolak(): void
    {
        $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Ana')
            ->call('pay', $this->method('Tunai'), null, 19999)
            ->assertDispatched('pay-failed', message: __('pos.pay.errors.received'));

        $this->assertSame(0, Order::count());
    }

    public function test_nama_wajib_kecuali_dimatikan(): void
    {
        $this->kasir()->call('addLine', $this->id('Americano'))->call('pay', $this->method('QRIS'))->assertDispatched('name-required');
        $this->assertSame(0, Order::count());

        $this->setting('policy.require_customer_name', false);
        $this->kasir()->call('pay', $this->method('QRIS'))->assertDispatched('paid');
        $this->assertNull(Order::sole()->customer_name);
    }

    public function test_metode_nonaktif_ditolak(): void
    {
        $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Ana')
            ->call('pay', $this->method('Kartu kredit'), Bank::first()->id)
            ->assertDispatched('pay-failed');

        $this->assertSame(0, Order::count());
    }

    public function test_menu_yang_baru_habis_ditolak_saat_bayar(): void
    {
        $kasir = $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Ana');
        MenuItem::firstWhere('name', 'Americano')->update(['is_sold_out' => true]);

        $kasir->call('pay', $this->method('QRIS'))->assertDispatched('pay-failed', message: __('pos.pay.errors.item_unavailable', ['item' => 'Americano']));
        $this->assertSame(0, Order::count());
    }

    public function test_harus_ada_shift_berjalan(): void
    {
        $kasir = $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Ana');
        $this->shift->forceFill(['closed_at' => now(), 'closed_by' => $this->user('Rani')->id])->save();

        $kasir->call('pay', $this->method('QRIS'))->assertDispatched('pay-failed', message: __('pos.pay.errors.no_shift'));
        $this->assertSame(0, Order::count());
    }

    public function test_pesanan_kosong_ditolak(): void
    {
        $this->kasir()->set('customerName', 'Ana')->call('pay', $this->method('QRIS'))->assertDispatched('pay-failed', message: __('pos.pay.errors.empty'));
    }

    public function test_diskon_disetujui_tersimpan_dengan_penyetuju(): void
    {
        $this->kasir()->call('addLine', $this->id('Cappuccino'), [], 4)->set('customerName', 'Ana')
            ->call('applyDiscount', 'percent', 30)->call('approve', '3333')
            ->call('pay', $this->method('QRIS'));

        $order = Order::sole();
        $this->assertSame([100000, 30000, 70000], [$order->subtotal, $order->discount_amount, $order->total]);
        $this->assertSame($this->user('Sinta')->id, $order->discount_approved_by);
    }

    public function test_persetujuan_diskon_dicek_ulang_saat_bayar(): void
    {
        $kasir = $this->kasir()->call('addLine', $this->id('Cappuccino'), [], 4)->set('customerName', 'Ana')
            ->call('applyDiscount', 'percent', 30)->call('approve', '3333');

        // Penyetuju kehilangan hak sebelum pesanan dibayar.
        $this->user('Sinta')->update(['is_active' => false]);

        $kasir->call('pay', $this->method('QRIS'))->assertDispatched('pay-failed', message: __('pos.pay.errors.discount_approval'));
        $this->assertSame(0, Order::count());
    }

    public function test_diskon_nominal_disetujui_tidak_berlaku_bila_subtotal_dikurangi(): void
    {
        // Rp 30.000 dari Rp 100.000 (30%) disetujui; item dikurangi jadi Rp 50.000 → 60% tanpa persetujuan baru.
        $kasir = $this->kasir()->call('addLine', $this->id('Cappuccino'), [], 4)->set('customerName', 'Ana')
            ->call('applyDiscount', 'amount', 30000)->call('approve', '3333')
            ->call('changeQty', 0, -1)->call('changeQty', 0, -1);

        $kasir->call('pay', $this->method('QRIS'))->assertDispatched('pay-failed', message: __('pos.pay.errors.discount_approval'));
        $kasir->call('hold')->assertDispatched('pay-failed', message: __('pos.pay.errors.discount_approval'));
        $this->assertSame(0, Order::count());

        // Kembali ke subtotal yang disetujui (atau lebih) → boleh.
        $kasir->call('changeQty', 0, 1)->call('changeQty', 0, 1)->call('pay', $this->method('QRIS'))->assertDispatched('paid');
        $this->assertSame(30000, Order::sole()->discount_amount);
    }

    public function test_open_bill_dengan_diskon_nominal_tetap_terikat_subtotal(): void
    {
        $this->kasir()->call('addLine', $this->id('Cappuccino'), [], 4)->set('customerName', 'Ana')
            ->call('applyDiscount', 'amount', 30000)->call('approve', '3333')->call('hold');

        // Item belum dibayar & tercetak: mengurangi perlu void + PIN; tambah item baru tetap boleh.
        $kasir = $this->kasir()->call('openHeld', Order::sole()->id)->call('addLine', $this->id('Air Mineral'));
        $kasir->call('pay', $this->method('QRIS'))->assertDispatched('paid');
    }

    public function test_pajak_dan_service_tersimpan(): void
    {
        $this->setting('charges.service_enabled', true);
        $this->setting('charges.tax_enabled', true);

        $this->kasir()->call('addLine', $this->id('Cappuccino'), [], 4)->set('customerName', 'Ana')->call('pay', $this->method('QRIS'));

        $order = Order::sole();
        $this->assertSame([5.0, 5000, 10.0, 10500, 115500], [$order->service_rate, $order->service_amount, $order->tax_rate, $order->tax_amount, $order->total]);
    }

    public function test_bawa_pulang_tidak_menyimpan_meja(): void
    {
        $kasir = $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Ana')->call('setTable', '4');
        $kasir->call('setType', OrderType::firstWhere('name', 'Bawa pulang')->id)->call('pay', $this->method('QRIS'));

        $this->assertNull(Order::sole()->table_number);
    }

    public function test_harga_disalin_sehingga_laporan_tidak_berubah(): void
    {
        $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Ana')->call('pay', $this->method('QRIS'));
        MenuItem::firstWhere('name', 'Americano')->update(['price' => 30000, 'name' => 'Americano Baru']);

        $item = Order::sole()->items->sole();
        $this->assertSame(['Americano', 20000], [$item->name, $item->unit_price]);
    }

    public function test_metode_qris_dengan_gambar_qr_ditampilkan_di_kasir(): void
    {
        $qris = PaymentMethod::firstWhere('name', 'QRIS');
        $qris->update(['qr_image' => 'qris/toko.png']);

        $this->assertStringContainsString('qris/toko.png', $qris->qrImageUrl());
        $this->kasir()->assertSee('toko.png', false);
    }
}
