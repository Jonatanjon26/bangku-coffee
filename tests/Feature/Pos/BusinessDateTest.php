<?php

namespace Tests\Feature\Pos;

use App\Enums\ReasonType;
use App\Livewire\Pos\Kasir;
use App\Models\Device;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Support\BusinessDate;
use App\Support\OrderCorrections;
use App\Support\SalesReport;
use App\Support\ShiftService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/** Buka lewat 00.00 (malam minggu): transaksi sebelum jam pergantian hari tetap hari dagang sebelumnya. */
class BusinessDateTest extends PosTestCase
{
    private Device $device;

    private string $token;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 18:00')); // Sabtu
        [$this->device, $this->token] = $this->tablet();
        $this->shift = app(ShiftService::class)->open($this->user('Rani'), $this->device, ShiftTemplate::firstWhere('name', 'Sore')->id, [100000 => 3], 0, null);
    }

    private function kasir()
    {
        $this->signIn($this->user('Rani'), $this->device, $this->token);

        return Livewire::withCookies([$this->cookieName() => $this->token])->test(Kasir::class);
    }

    private function sellQris(string $name): Order
    {
        $this->kasir()->call('addLine', MenuItem::firstWhere('name', 'Americano')->id)->set('customerName', $name)
            ->call('pay', PaymentMethod::firstWhere('name', 'QRIS')->id)->assertDispatched('paid');

        return Order::latest('id')->first();
    }

    public function test_jam_pergantian_hari(): void
    {
        $this->assertSame('2026-10-24', BusinessDate::today(CarbonImmutable::parse('2026-10-25 03:59'))->toDateString());
        $this->assertSame('2026-10-25', BusinessDate::today(CarbonImmutable::parse('2026-10-25 04:00'))->toDateString());

        $this->setting('orders.day_change_hour', 0); // kalender biasa
        $this->assertSame('2026-10-25', BusinessDate::today(CarbonImmutable::parse('2026-10-25 00:30'))->toDateString());
    }

    public function test_transaksi_lewat_tengah_malam_tetap_hari_dagang_yang_sama(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-24 23:30'));
        $a = $this->sellQris('Ana');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 00:30'));
        $b = $this->sellQris('Budi');

        // Antrean berlanjut & kode bertanggal hari dagang Sabtu.
        $this->assertSame([1, 2], [$a->queue_number, $b->queue_number]);
        $this->assertSame('B-1024-002', $b->code);
        $this->assertSame('2026-10-24', $b->paid_business_date->toDateString());
        $this->assertSame('2026-10-24', $b->payments()->sole()->business_date->toDateString());

        // Laporan/dasbor jam 00.30 menampilkan hari Sabtu (kedua transaksi).
        $this->assertSame(40000, app(SalesReport::class)->dashboard()['today']['total']);

        // Refund pukul 00.40 untuk pesanan yang dibayar 23.30: masih hari yang sama.
        $this->travelTo(CarbonImmutable::parse('2026-10-25 00:40'));
        $this->assertTrue($a->fresh()->refundableToday());
        app(OrderCorrections::class)->refund($a->fresh(), $this->user('Rani'), $this->user('Sinta'), Reason::ofType(ReasonType::Refund)->first()->id, null, $this->shift);

        // Tutup hari 00.45: settlement EDC memuat QRIS 00.30 (yang 23.30 direfund & di-void di mesin EDC).
        $this->travelTo(CarbonImmutable::parse('2026-10-25 00:45'));
        $preview = app(ShiftService::class)->closingPreview($this->shift->fresh(), [100000 => 3], 0, true);
        $this->assertSame([['QRIS', 20000]], array_map(fn ($r) => [$r['label'], $r['pos_amount']], $preview['edc']));
    }

    public function test_open_bill_malam_tidak_dianggap_lewat_hari_sebelum_jam_pergantian(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-24 23:00'));
        $this->kasir()->call('addLine', MenuItem::firstWhere('name', 'Americano')->id)->set('customerName', 'Cici')->call('hold');
        $order = Order::sole();

        $this->travelTo(CarbonImmutable::parse('2026-10-25 01:30'));
        $this->assertFalse($order->fresh()->needsDayApproval());
        $this->kasir()->call('openHeld', $order->id)->assertNotDispatched('approval-required');

        $this->travelTo(CarbonImmutable::parse('2026-10-25 10:00'));
        $this->assertTrue($order->fresh()->needsDayApproval());
    }
}
