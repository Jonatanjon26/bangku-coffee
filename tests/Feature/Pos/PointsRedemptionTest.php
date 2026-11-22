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
use App\Support\OrderCorrections;
use App\Support\Printing\Documents;
use App\Support\ShiftService;
use Livewire\Livewire;

/** Tukar poin: potongan saat bayar, saldo dicek & dipotong di server, dikembalikan saat refund. */
class PointsRedemptionTest extends PosTestCase
{
    private Device $device;

    private string $token;

    private Shift $shift;

    private Customer $ana;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->device, $this->token] = $this->tablet();
        $this->shift = app(ShiftService::class)->open($this->user('Rani'), $this->device, ShiftTemplate::first()->id, [100000 => 3], 0, null);
        $this->setting('customers.loyalty_enabled', true);
        $this->setting('customers.point_per', 10000);
        $this->setting('customers.point_value', 100);
        $this->setting('customers.redeem_min_points', 50);
        $this->ana = Customer::create(['name' => 'Ana', 'phone' => '081234567890']);
        $this->ana->forceFill(['points' => 200])->save();
    }

    /** Pesanan Rp 100.000 (4 Cappuccino) untuk Ana. */
    private function kasir()
    {
        $this->signIn($this->user('Rani'), $this->device, $this->token);

        return Livewire::withCookies([$this->cookieName() => $this->token])->test(Kasir::class)
            ->call('addLine', MenuItem::firstWhere('name', 'Cappuccino')->id, [], 4)
            ->call('linkCustomer', $this->ana->id);
    }

    private function qris(): int
    {
        return PaymentMethod::firstWhere('name', 'QRIS')->id;
    }

    public function test_tukar_poin_mengurangi_tagihan_dan_saldo(): void
    {
        $kasir = $this->kasir()->assertSee(__('pos.points.button', ['balance' => 200]))
            ->call('redeemPoints', 100)->assertSet('order.points', 100)->assertSee('− Rp 10.000');

        $kasir->call('pay', $this->qris())->assertDispatched('paid');

        $order = Order::sole();
        $this->assertSame([100, 10000, 90000], [$order->points_redeemed, $order->points_discount, $order->total]);
        // 200 − 100 ditukar + 9 poin dari belanja Rp 90.000.
        $this->assertSame(109, $this->ana->fresh()->points);
        $this->assertTrue(AuditLog::where('event', 'customer.points_redeemed')->where('new_values->points', 100)->exists());

        // Riwayat di kasir menampilkan potongan poin.
        $this->kasir()->call('selectHistory', $order->id)->assertSee(__('pos.print.points', ['points' => 100]));

        $receipt = collect(app(Documents::class)->receipt($order)['lines'] ?? [])->pluck('text')->implode("\n");
        $this->assertStringContainsString(__('pos.print.points', ['points' => 100]), $receipt);
    }

    public function test_penukaran_divalidasi_di_server(): void
    {
        $kasir = $this->kasir();

        $kasir->call('redeemPoints', 20)->assertDispatched('toast', message: __('pos.points.errors.points_min', ['min' => 50]))->assertSet('order.points', 0);
        $kasir->call('redeemPoints', 500)->assertDispatched('toast', message: __('pos.points.errors.points_balance', ['balance' => 200]))->assertSet('order.points', 0);

        // Nilai poin tidak boleh melebihi tagihan (Rp 100.000 = 1000 poin; saldo dinaikkan dulu).
        $this->ana->forceFill(['points' => 5000])->save();
        $kasir->call('redeemPoints', 1500)->assertDispatched('toast', message: __('pos.points.errors.points_exceed'))->assertSet('order.points', 0);

        $this->setting('customers.loyalty_enabled', false);
        $kasir->call('redeemPoints', 100)->assertDispatched('toast', message: __('pos.points.errors.points_unavailable'))->assertSet('order.points', 0);
    }

    public function test_saldo_berubah_sebelum_bayar_ditolak_tanpa_pesanan(): void
    {
        $kasir = $this->kasir()->call('redeemPoints', 150);

        // Di tablet lain poin Ana dipakai lebih dulu.
        $this->ana->forceFill(['points' => 100])->save();

        $kasir->call('pay', $this->qris())->assertDispatched('pay-failed', message: __('pos.points.errors.points_balance', ['balance' => 100]));
        $this->assertSame(0, Order::count());
        $this->assertSame(100, $this->ana->fresh()->points);
    }

    public function test_item_dikurangi_sampai_poin_melebihi_tagihan_ditolak_saat_bayar(): void
    {
        $this->ana->forceFill(['points' => 2000])->save();
        $kasir = $this->kasir()->call('redeemPoints', 900);
        $kasir->call('changeQty', 0, -1)->call('changeQty', 0, -1); // tagihan jadi Rp 50.000

        $kasir->call('pay', $this->qris())->assertDispatched('pay-failed', message: __('pos.points.errors.points_exceed'));
        $this->assertSame(0, Order::count());
    }

    public function test_refund_mengembalikan_poin_yang_ditukar(): void
    {
        $this->kasir()->call('redeemPoints', 100)->call('pay', $this->qris());
        $this->assertSame(109, $this->ana->fresh()->points);

        app(OrderCorrections::class)->refund(Order::sole(), $this->user('Rani'), $this->user('Sinta'), Reason::ofType(ReasonType::Refund)->first()->id, null, $this->shift);

        $this->assertSame(200, $this->ana->fresh()->points);
    }

    public function test_open_bill_disimpan_tanpa_potongan_poin(): void
    {
        $this->kasir()->call('redeemPoints', 100)->call('hold')->assertDispatched('held');

        $order = Order::sole();
        $this->assertSame([0, 0, 100000], [$order->points_redeemed, $order->points_discount, $order->total]);
        $this->assertSame(200, $this->ana->fresh()->points);
    }

    public function test_lepas_pelanggan_membatalkan_poin(): void
    {
        $this->kasir()->call('redeemPoints', 100)->call('unlinkCustomer')->assertSet('order.points', 0);
    }

    public function test_refund_saat_poin_yang_didapat_sudah_ditukar(): void
    {
        // Ana 200 poin: bayar Rp 100.000 tukar 100 poin → +9 poin = 109; lalu 109 poin habis ditukar di transaksi lain.
        $this->kasir()->call('redeemPoints', 100)->call('pay', $this->qris());
        $first = Order::sole();
        $this->ana->forceFill(['points' => 0])->save();

        app(OrderCorrections::class)->refund($first, $this->user('Rani'), $this->user('Sinta'), Reason::ofType(ReasonType::Refund)->first()->id, null, $this->shift);

        // 0 + 100 dikembalikan − 9 yang didapat = 91 (dulu: max(0, 0 − 9) + 100 = 100).
        $this->assertSame(91, $this->ana->fresh()->points);
    }
}
