<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\ReasonType;
use App\Livewire\Pos\Kasir;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Support\Checkout;
use App\Support\OrderDraft;
use App\Support\ShiftService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class CorrectionsTest extends PosTestCase
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

    private function reason(ReasonType $type, string $label): int
    {
        return Reason::ofType($type)->firstWhere('label', $label)->id;
    }

    /** Open bill: 2× Croissant (tercetak) atas nama Dedi. */
    private function heldOrder(): Order
    {
        $this->kasir()->call('addLine', $this->id('Croissant Butter'), [], 2)->set('customerName', 'Dedi')->call('hold');

        return Order::sole();
    }

    private function paidOrder(string $method = 'Tunai'): Order
    {
        $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Eka')->call('pay', PaymentMethod::firstWhere('name', $method)->id, null, 20000);

        return Order::latest('id')->first();
    }

    /* ---------------------------------------------------------------- void item tercetak */

    public function test_mengurangi_item_tercetak_perlu_alasan_dan_pin_penyetuju(): void
    {
        $order = $this->heldOrder();
        $kasir = $this->kasir()->call('openHeld', $order->id);

        $kasir->call('changeQty', 0, -1)->assertDispatched('correction-required', kind: 'item', needsPin: true);
        $this->assertSame(2, $kasir->get('order')['lines'][0]['qty']);

        // Tanpa alasan / alasan dari daftar lain / PIN kasir biasa → ditolak.
        $kasir->call('confirmCorrection', 0, '', '3333')->assertDispatched('correction-failed', message: __('pos.corrections.errors.reason'));
        $kasir->call('confirmCorrection', $this->reason(ReasonType::Refund, 'Salah input'), '', '3333')->assertDispatched('correction-failed');
        $kasir->call('confirmCorrection', $this->reason(ReasonType::Void, 'Salah input'), '', '1111')->assertDispatched('correction-failed');
        $this->assertSame(2, $kasir->get('order')['lines'][0]['qty']);

        $kasir->call('confirmCorrection', $this->reason(ReasonType::Void, 'Salah input'), '', '3333')->assertDispatched('correction-done');
        $this->assertSame([1, 1], [$kasir->get('order')['lines'][0]['qty'], $kasir->get('order')['lines'][0]['printed']]);

        $kasir->call('hold');
        $this->assertSame(1, $order->items()->sole()->quantity);
        $log = AuditLog::where('event', 'order_item.voided')->sole();
        $this->assertEquals(['item' => 'Croissant Butter', 'qty' => 1, 'reason' => 'Salah input', 'note' => null], $log->new_values);
        $this->assertSame($this->user('Sinta')->id, $log->approved_by);
        $this->assertSame($this->user('Rani')->id, $log->user_id);
        $this->assertSame($order->id, $log->auditable_id);
    }

    public function test_item_belum_tercetak_bebas_dikurangi(): void
    {
        $order = $this->heldOrder();
        $kasir = $this->kasir()->call('openHeld', $order->id)->call('addLine', $this->id('Air Mineral'));
        $index = collect($kasir->get('order')['lines'])->search(fn ($l) => $l['item_id'] === $this->id('Air Mineral'));

        $kasir->call('changeQty', $index, -1)->assertNotDispatched('correction-required');
        $this->assertCount(1, $kasir->get('order')['lines']);
    }

    public function test_server_menolak_item_tercetak_hilang_tanpa_catatan_void(): void
    {
        $order = $this->heldOrder();
        $state = app(Checkout::class)->toDraft($order)['state'];
        $state['lines'][0]['qty'] = 1; // dimanipulasi tanpa alasan/PIN

        $this->expectException(ValidationException::class);
        app(Checkout::class)->hold($this->user('Rani'), $this->device, $state, 'Dedi');
    }

    public function test_catatan_void_dengan_penyetuju_tanpa_hak_ditolak_server(): void
    {
        $order = $this->heldOrder();
        $state = app(Checkout::class)->toDraft($order)['state'];
        $state['voids'][] = ['signature' => OrderDraft::signature($state['lines'][0]['item_id'], $state['lines'][0]['picks'], ''), 'name' => 'Croissant Butter', 'qty' => 2, 'reason' => 'x', 'note' => null, 'approved_by' => $this->user('Rani')->id];
        $state['lines'] = [];
        $state['lines'][] = ['item_id' => $this->id('Air Mineral'), 'picks' => [], 'qty' => 1, 'note' => '', 'printed' => 0];

        $this->expectException(ValidationException::class);
        app(Checkout::class)->hold($this->user('Rani'), $this->device, $state, 'Dedi');
    }

    public function test_alasan_lainnya_wajib_catatan(): void
    {
        Reason::create(['type' => ReasonType::Void, 'label' => 'Lainnya', 'requires_note' => true]);
        $kasir = $this->kasir()->call('openHeld', $this->heldOrder()->id)->call('changeQty', 0, -1);

        $kasir->call('confirmCorrection', $this->reason(ReasonType::Void, 'Lainnya'), '', '3333')->assertDispatched('correction-failed', message: __('pos.corrections.errors.note'));
        $kasir->call('confirmCorrection', $this->reason(ReasonType::Void, 'Lainnya'), 'tumpah', '3333')->assertDispatched('correction-done');
    }

    /* ---------------------------------------------------------------- riwayat, refund, batal */

    public function test_riwayat_menampilkan_transaksi_hari_ini_dan_open_bill(): void
    {
        $this->heldOrder();
        $paid = $this->paidOrder();

        $this->kasir()->assertSee(__('pos.history.title'))->assertSee('#1 · Dedi')->assertSee('#2 · Eka')
            ->call('selectHistory', $paid->id)->assertSee(__('pos.history.refund'))->assertSee('1× Americano');
    }

    public function test_refund_dari_kasir_dengan_pin_dan_uang_tunai_dari_laci(): void
    {
        $order = $this->paidOrder('Tunai');

        $this->kasir('Dimas')->call('startRefund', $order->id)->assertDispatched('correction-required', kind: 'refund', needsPin: true)
            ->call('confirmCorrection', $this->reason(ReasonType::Refund, 'Komplain'), '', '9999')
            ->assertDispatched('correction-done');

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $refund = $order->refunds()->sole();
        $this->assertSame([20000, 'Komplain', $this->shift->id], [$refund->amount, $refund->reason_label, $refund->shift_id]);
        $this->assertSame($this->user('Dimas')->id, $refund->refunded_by);
        $this->assertSame($this->user('Owner')->id, $refund->approved_by);
        $this->assertSame(1, $order->payments()->count()); // pembayaran asli tetap
        $this->assertSame(300000, $this->shift->fresh()->summarize()['expected']); // 300.000 + 20.000 − 20.000
        $this->assertTrue(AuditLog::where('event', 'order.refunded')->where('auditable_id', $order->id)->exists());
    }

    public function test_refund_tanpa_pin_bila_pengaturan_dimatikan(): void
    {
        $this->setting('policy.refund_needs_approval', false);
        $order = $this->paidOrder('QRIS');

        $this->kasir()->call('startRefund', $order->id)->assertDispatched('correction-required', needsPin: false)
            ->call('confirmCorrection', $this->reason(ReasonType::Refund, 'Salah input'));

        $this->assertNull($order->refunds()->sole()->approved_by);
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
    }

    public function test_refund_tidak_bisa_dua_kali(): void
    {
        $order = $this->paidOrder();
        $kasir = $this->kasir()->call('startRefund', $order->id);
        $kasir->call('confirmCorrection', $this->reason(ReasonType::Refund, 'Komplain'), '', '9999');

        $kasir->call('startRefund', $order->id)->assertNotDispatched('correction-required');
        $this->assertSame(1, $order->refunds()->count());
    }

    public function test_batal_open_bill_dari_kasir(): void
    {
        $order = $this->heldOrder();
        $kasir = $this->kasir()->call('openHeld', $order->id);

        $kasir->call('startVoid', $order->id)->call('confirmCorrection', $this->reason(ReasonType::Void, 'Pelanggan batal'), '', '3333')
            ->assertDispatched('correction-done')
            ->assertSet('order.order_id', null);

        $order->refresh();
        $this->assertSame(OrderStatus::Voided, $order->status);
        $this->assertSame(['Pelanggan batal', $this->user('Rani')->id], [$order->void_reason_label, $order->voided_by]);
        $this->assertSame(2, $order->items()->sole()->quantity); // item asli tetap
        $this->assertSame($this->user('Sinta')->id, AuditLog::firstWhere('event', 'order.voided')->approved_by);
        $this->assertSame(0, Order::open()->count());
    }

    /* ---------------------------------------------------------------- batas dari tinjauan keamanan */

    public function test_refund_dari_kasir_hanya_transaksi_hari_ini(): void
    {
        $order = $this->paidOrder();
        $refund = $this->reason(ReasonType::Refund, 'Salah input');
        $this->travel(1)->days();
        $this->shift->forceFill(['closed_at' => now()])->save();
        app(ShiftService::class)->open($this->user('Rani'), $this->device, ShiftTemplate::first()->id, [100000 => 3], 0, null);

        // Panggilan langsung ke aksi (UI hanya menampilkan riwayat hari ini).
        $this->kasir()->call('startRefund', $order->id)->assertNotDispatched('correction-required')
            ->assertDispatched('toast', message: __('pos.corrections.errors.refund_today'))
            ->call('confirmCorrection', $refund, '', '3333');

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_refund_tunai_wajib_shift_berjalan(): void
    {
        $order = $this->paidOrder();
        $this->shift->forceFill(['closed_at' => now()])->save();

        $this->kasir()->call('startRefund', $order->id)
            ->call('confirmCorrection', $this->reason(ReasonType::Refund, 'Salah input'), '', '3333')
            ->assertDispatched('correction-failed', message: __('pos.pay.errors.no_shift'));

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_riwayat_tidak_bisa_membuka_transaksi_lama(): void
    {
        $order = $this->paidOrder();
        $this->travel(2)->days();

        $this->kasir()->call('selectHistory', $order->id)->assertSet('historyId', null)->assertDontSee($order->code);
    }
}
