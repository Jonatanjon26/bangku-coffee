<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\ReasonType;
use App\Livewire\Pos\Kasir;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Support\Checkout;
use App\Support\OrderDraft;
use App\Support\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class OpenBillTest extends PosTestCase
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

    private function holdAna()
    {
        return $this->kasir()->call('addLine', $this->id('Croissant Butter'))->call('addLine', $this->id('Americano'))->set('customerName', 'Ana')->call('hold');
    }

    public function test_simpan_membuat_open_bill_dengan_kode_tetap_tanpa_penjualan(): void
    {
        $this->holdAna()->assertDispatched('held')->assertDispatched('toast')->assertSet('order.lines', [])->assertSet('customerName', '');

        $order = Order::with('items')->sole();
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertSame('B-'.now()->format('md').'-001', $order->code);
        $this->assertSame(40000, $order->total);
        $this->assertNull($order->paid_at);
        $this->assertSame($this->shift->id, $order->opened_shift_id);
        $this->assertTrue($order->items->every(fn ($i) => $i->printed_quantity === $i->quantity));
        $this->assertSame(0, $order->payments()->count());

        // Belum dihitung sebagai penjualan shift.
        $this->assertSame(0, $this->shift->summarize()['total']);
        $this->assertSame(0, Order::sold()->count());
    }

    public function test_daftar_tersimpan_dan_buka_lagi(): void
    {
        $this->holdAna();

        $kasir = $this->kasir()->assertSee(__('pos.held.button'))->assertSee('#1 · Ana');
        $kasir->call('openHeld', Order::sole()->id)
            ->assertSet('customerName', 'Ana')
            ->assertSee('B-'.now()->format('md').'-001 · '.__('pos.held.saved_tag'))
            ->assertSee(__('pos.held.printed'));

        $this->assertCount(2, $kasir->get('order')['lines']);
        $this->assertTrue(collect($kasir->get('order')['lines'])->every(fn ($l) => $l['printed'] === $l['qty']));
    }

    public function test_tambahan_item_menjadi_tiket_tambahan_dan_kode_tetap(): void
    {
        $this->holdAna();
        $order = Order::sole();
        $draft = app(Checkout::class)->toDraft($order);
        $state = app(OrderDraft::class)->add($draft['state'], app(OrderDraft::class)->line($this->id('Air Mineral'), [], 2, ''));
        $state['lines'][0]['qty'] = 2; // satu croissant lagi

        $result = app(Checkout::class)->hold($this->user('Dimas'), $this->device, $state, 'Ana');

        $this->assertTrue($result->isAddition);
        $this->assertSame($order->id, $result->order->id);
        $this->assertSame($order->code, $result->order->code);
        $this->assertEqualsCanonicalizing([['Croissant Butter', 1], ['Air Mineral', 2]], array_map(fn ($t) => [$t['name'], $t['qty']], $result->ticket));
        $this->assertSame(1, Order::count());
        $this->assertSame(20000 * 2 + 20000 + 8000 * 2, $result->order->fresh()->total);
    }

    public function test_ubah_varian_item_yang_sudah_tercetak_perlu_alasan_dan_pin_lalu_dicetak_ulang(): void
    {
        $this->holdAna();
        $kasir = $this->kasir()->call('openHeld', Order::sole()->id);
        $americano = collect($kasir->get('order')['lines'])->search(fn ($l) => $l['item_id'] === $this->id('Americano'));
        $temp = ModifierGroup::firstWhere('name', 'Suhu');
        $panas = $temp->options()->where('name', 'Panas')->value('id');

        $kasir->call('updateLine', $americano, [$temp->id => $panas], 1, '')->assertDispatched('correction-required', kind: 'item');
        $this->assertSame(1, $kasir->get('order')['lines'][$americano]['printed']); // belum berubah

        $reason = Reason::ofType(ReasonType::Void)->firstWhere('label', 'Salah input');
        $kasir->call('confirmCorrection', $reason->id, '', '3333')->assertDispatched('correction-done');

        $line = $kasir->get('order')['lines'][$americano];
        $this->assertSame([0, $panas], [$line['printed'], $line['picks'][$temp->id]]);
        $this->assertCount(1, $kasir->get('order')['voids']);
    }

    public function test_bayar_open_bill_memakai_pesanan_yang_sama_dan_dihitung_di_shift_bayar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00'));
        $this->holdAna();
        $order = Order::sole();

        // Shift Pagi ditutup, Sore dibuka oleh kasir lain (open bill dialihkan).
        $this->shift->forceFill(['closed_at' => now(), 'closed_by' => $this->user('Rani')->id, 'float_left' => 300000])->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00'));
        $next = app(ShiftService::class)->open($this->user('Dimas'), $this->device, ShiftTemplate::first()->id, [100000 => 3], 0, null);

        $this->kasir('Dimas')
            ->assertSee(__('pos.held.late', ['days' => 1]))
            ->call('openHeld', $order->id)
            ->assertDispatched('approval-required') // lewat hari: PIN penyetuju
            ->call('approve', '3333')
            ->call('pay', $this->method('Tunai'), null, 50000)
            ->assertDispatched('paid', change: 10000);

        $order->refresh();
        $this->assertSame(1, Order::count());
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('B-1019-001', $order->code);
        $this->assertSame('2026-10-19', $order->business_date->toDateString());
        $this->assertSame('2026-10-20', $order->paid_business_date->toDateString());
        $this->assertSame($this->shift->id, $order->opened_shift_id);
        $this->assertSame($next->id, $order->paid_shift_id);
        $this->assertSame($this->user('Dimas')->id, $order->paid_by);
        $this->assertSame($next->id, $order->payments()->sole()->shift_id);
        $this->assertSame(40000, $next->summarize()['total']);
    }

    public function test_tidak_bisa_menimpa_perubahan_dari_tablet_lain(): void
    {
        $this->holdAna();
        $order = Order::sole();
        $stale = app(Checkout::class)->toDraft($order)['state'];

        // Tablet lain menambah item lebih dulu.
        $fresh = app(OrderDraft::class)->add($stale, app(OrderDraft::class)->line($this->id('Air Mineral'), [], 1, ''));
        $this->travel(1)->seconds();
        app(Checkout::class)->hold($this->user('Dimas'), $this->device, $fresh, 'Ana');

        $this->expectException(ValidationException::class);
        app(Checkout::class)->pay($this->user('Rani'), $this->device, $stale, 'Ana', $this->method('QRIS'), null, 0, true);
    }

    public function test_open_bill_yang_sudah_dibayar_tidak_bisa_dibuka_atau_dibayar_lagi(): void
    {
        $this->holdAna();
        $order = Order::sole();
        $state = app(Checkout::class)->toDraft($order)['state'];
        app(Checkout::class)->pay($this->user('Rani'), $this->device, $state, 'Ana', $this->method('QRIS'), null, 0, true);

        $this->kasir()->call('openHeld', $order->id)->assertSet('order.order_id', null)->assertDispatched('toast', message: __('pos.held.errors.not_open'));

        try {
            app(Checkout::class)->pay($this->user('Rani'), $this->device, $state, 'Ana', $this->method('QRIS'), null, 0, true);
            $this->fail('Pembayaran ganda seharusnya ditolak');
        } catch (ValidationException) {
            $this->assertSame(1, $order->payments()->count());
        }
    }

    public function test_tutup_pesanan_tersimpan_tidak_menghapusnya(): void
    {
        $this->holdAna();
        $this->kasir()->call('openHeld', Order::sole()->id)->call('clearOrder')->assertSet('order.order_id', null);

        $this->assertSame(OrderStatus::Open, Order::sole()->status);
        $this->assertSame(2, Order::sole()->items()->count());
    }

    public function test_simpan_juga_wajib_nama_dan_shift(): void
    {
        $this->kasir()->call('addLine', $this->id('Americano'))->call('hold')->assertDispatched('name-required');
        $this->assertSame(0, Order::count());

        $this->shift->forceFill(['closed_at' => now(), 'closed_by' => $this->user('Rani')->id])->save();
        $this->kasir()->set('customerName', 'Ana')->call('hold')->assertDispatched('pay-failed', message: __('pos.pay.errors.no_shift'));
        $this->assertSame(0, Order::count());
    }

    public function test_nomor_antrean_open_bill_tidak_dipakai_ulang(): void
    {
        $this->holdAna();
        $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Budi')->call('pay', $this->method('QRIS'));

        $this->assertSame([1, 2], Order::orderBy('id')->pluck('queue_number')->all());
    }

    public function test_pesanan_baru_tidak_tersimpan_dua_kali_dari_permintaan_bersamaan(): void
    {
        $kasir = $this->kasir()->call('addLine', $this->id('Croissant Butter'));
        $state = $kasir->get('order');
        $checkout = app(Checkout::class);
        $rani = $this->user('Rani');

        // Dua permintaan dengan snapshot yang sama (ketuk ganda Bayar).
        $checkout->pay($rani, $this->device, $state, 'Ana', $this->method('QRIS'), null, 0, false);

        try {
            $checkout->pay($rani, $this->device, $state, 'Ana', $this->method('QRIS'), null, 0, false);
            $this->fail('pembayaran kedua harus ditolak');
        } catch (ValidationException $e) {
            $this->assertSame(__('pos.pay.errors.already_processed'), collect($e->errors())->flatten()->first());
        }

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        // Nomor antrean tidak terpakai oleh percobaan yang ditolak (transaksi dibatalkan).
        $next = OrderDraft::blank($state['type_id']);
        $next['lines'] = $state['lines'];
        $this->assertSame(2, $checkout->hold($rani, $this->device, $next, 'Budi')->order->queue_number);
    }

    public function test_ganti_varian_berharga_sama_di_tablet_lain_terdeteksi(): void
    {
        $this->freezeTime(); // updated_at sama persis (presisi detik)
        $latte = MenuItem::firstWhere('name', 'Caffe Latte');
        $group = $latte->modifierGroups()->whereHas('options', fn ($q) => $q->where('price_delta', 0), '>=', 2)->first();
        $this->assertNotNull($group, 'perlu grup varian dengan dua pilihan berharga sama');
        [$a, $b] = $group->options()->where('price_delta', 0)->take(2)->get()->all();

        $this->kasir()->call('addLine', $latte->id, [$group->id => $a->id])->set('customerName', 'Ana')->call('hold');
        $order = Order::sole();
        $tabletA = app(Checkout::class)->toDraft($order)['state'];

        // Tablet B mengganti pilihan (harga & jumlah item sama).
        $order->items()->first()->modifiers()->where('modifier_group_id', $group->id)->update(['modifier_option_id' => $b->id, 'option_name' => $b->name]);

        try {
            app(Checkout::class)->hold($this->user('Rani'), $this->device, $tabletA, 'Ana');
            $this->fail('perubahan dari tablet lain harus terdeteksi');
        } catch (ValidationException $e) {
            $this->assertSame(__('pos.held.errors.changed'), collect($e->errors())->flatten()->first());
        }
    }

    /** Open bill Latte (varian Ukuran Besar) yang sudah dibuat barista. */
    private function heldLatte(): Order
    {
        $latte = MenuItem::firstWhere('name', 'Caffe Latte');
        $size = $latte->modifierGroups()->firstWhere('name', 'Ukuran');
        $big = $size->options()->where('price_delta', '>', 0)->first();
        $this->kasir()->call('addLine', $latte->id, [$size->id => $big->id])->set('customerName', 'Ana')->call('hold');

        return Order::sole();
    }

    public function test_open_bill_tetap_bisa_dibayar_walau_menu_atau_varian_berubah(): void
    {
        $order = $this->heldLatte();
        $total = $order->total;
        $latte = MenuItem::firstWhere('name', 'Caffe Latte');
        $option = $order->items()->first()->modifiers()->whereNotNull('modifier_option_id')->where('price_delta', '>', 0)->first();

        // Setelah barang dibuat: menu habis, pilihan varian dinonaktifkan, grup varian baru ditambahkan, harga naik.
        $latte->update(['is_sold_out' => true, 'price' => $latte->price + 10000]);
        ModifierOption::whereKey($option->modifier_option_id)->update(['is_active' => false, 'price_delta' => 9000]);
        $latte->modifierGroups()->syncWithoutDetaching([ModifierGroup::firstWhere('name', 'Gula')->id]);

        $kasir = $this->kasir()->call('openHeld', $order->id)->assertDontSee(__('pos.order.unavailable_badge'));
        $kasir->call('pay', $this->method('QRIS'))->assertDispatched('paid');

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame($total, $order->total); // harga saat dipesan
        $this->assertSame($option->option_name, $order->items()->first()->modifiers()->whereNotNull('modifier_option_id')->where('price_delta', '>', 0)->value('option_name'));
    }

    public function test_open_bill_menu_dihapus_tetap_bisa_dibayar(): void
    {
        $order = $this->heldLatte();
        MenuItem::firstWhere('name', 'Caffe Latte')->delete();

        $this->kasir()->call('openHeld', $order->id)->call('pay', $this->method('QRIS'))->assertDispatched('paid');
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_tambah_jumlah_menu_yang_habis_tetap_ditolak(): void
    {
        $order = $this->heldLatte();
        MenuItem::firstWhere('name', 'Caffe Latte')->update(['is_sold_out' => true]);

        $kasir = $this->kasir()->call('openHeld', $order->id)->call('changeQty', 0, 1);
        $kasir->call('pay', $this->method('QRIS'))->assertDispatched('pay-failed', message: __('pos.pay.errors.item_unavailable', ['item' => 'Caffe Latte']));
        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
    }

    public function test_tambahan_item_sama_memakai_harga_saat_dipesan_dan_masuk_tiket_tambahan(): void
    {
        $order = $this->heldLatte();
        $unit = $order->items()->first()->unit_price;
        MenuItem::firstWhere('name', 'Caffe Latte')->increment('price', 5000);

        $kasir = $this->kasir()->call('openHeld', $order->id)->call('changeQty', 0, 1)->call('hold');
        $kasir->assertDispatched('held');

        $item = $order->fresh()->items()->first();
        $this->assertSame([2, $unit, 2], [$item->quantity, $item->unit_price, $item->printed_quantity]);
    }

    public function test_open_bill_lewat_hari_perlu_pin_walau_shift_ditutup_tanpa_tutup_hari(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19 20:00'));
        $this->holdAna();
        $order = Order::sole();
        // Shift ditutup tanpa "tutup hari" (sakelar dimatikan) → tidak ada persetujuan lewat hari saat tutup.
        app(ShiftService::class)->close($this->user('Rani'), $this->device, ['counts' => [100000 => 3], 'float_left' => 300000, 'day_close' => false], null);

        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00'));
        app(ShiftService::class)->open($this->user('Rani'), $this->device, ShiftTemplate::first()->id, [100000 => 3], 0, null);
        $this->assertTrue($order->fresh()->needsDayApproval());

        // Dibuka dari kasir → minta PIN; draf belum terisi.
        $kasir = $this->kasir()->call('openHeld', $order->id)->assertDispatched('approval-required')->assertSet('order.order_id', null);

        // Dibayar langsung lewat Checkout tanpa persetujuan → ditolak di server.
        try {
            app(Checkout::class)->pay($this->user('Rani'), $this->device, app(Checkout::class)->toDraft($order)['state'], 'Ana', $this->method('QRIS'), null, 0, false);
            $this->fail('open bill lewat hari tanpa persetujuan tidak boleh dibayar');
        } catch (ValidationException $e) {
            $this->assertSame(__('pos.held.errors.day_approval'), collect($e->errors())->flatten()->first());
        }

        // PIN kasir biasa ditolak; PIN penyetuju membuka & mencatat persetujuan.
        $kasir->call('approve', '1111')->assertDispatched('approval-failed');
        $kasir->call('approve', '3333')->assertDispatched('approval-done')->assertSet('order.order_id', $order->id);
        $this->assertSame($this->user('Sinta')->id, $order->fresh()->day_carry_approved_by);
        $this->assertTrue(AuditLog::where('event', 'approval.open_bill_day')->exists());

        $kasir->call('pay', $this->method('QRIS'))->assertDispatched('paid');
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_open_bill_lewat_hari_tanpa_pin_bila_pengaturan_dimatikan(): void
    {
        $this->setting('policy.open_bill_day_approval', false);
        $this->holdAna();
        $this->travel(1)->days();

        $this->kasir()->call('openHeld', Order::sole()->id)->assertNotDispatched('approval-required')->assertSet('order.order_id', Order::sole()->id);
    }
}
