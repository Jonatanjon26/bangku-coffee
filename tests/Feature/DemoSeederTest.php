<?php

namespace Tests\Feature;

use App\Enums\CashCountStage;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\EdcSettlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.email' => 'owner@bangkucoffee.id']);
        // Data contoh hanya sampai "jam sekarang": tes dijalankan setelah tengah malam tidak punya transaksi hari ini.
        $this->travelTo(CarbonImmutable::parse('2026-10-19 21:30'));
    }

    public function test_data_contoh_14_hari_konsisten(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertGreaterThan(300, Order::count());
        $this->assertSame(12, Customer::count());
        $this->assertSame(14, DB::table('order_sequences')->count());
        $this->assertSame(0, Order::open()->count());

        // Setiap pesanan dibayar penuh; total = subtotal + item.
        $this->assertSame(0, Order::whereRaw('total != (select sum(amount) from payments where payments.order_id = orders.id)')->count());
        $this->assertSame(0, Order::whereRaw('subtotal != (select sum(unit_price * quantity) from order_items where order_items.order_id = orders.id)')->count());
        $this->assertSame(Payment::count(), Order::count());

        // Kode pesanan unik dan sesuai antrean.
        $order = Order::orderBy('id')->first();
        $this->assertSame(Order::makeCode($order->business_date, $order->queue_number), $order->code);

        // Refund tidak menghapus transaksi asli.
        $refund = Refund::firstOrFail();
        $this->assertSame(OrderStatus::Refunded, $refund->order->status);
        $this->assertSame($refund->order->total, $refund->amount);
        $this->assertNotNull($refund->approved_by);
    }

    public function test_tutup_shift_cocok_dengan_rumus_kas(): void
    {
        $this->seed(DemoSeeder::class);

        foreach (Shift::whereNotNull('closed_at')->get() as $shift) {
            $s = $shift->summarize();
            $this->assertSame($s['expected'], $shift->expected_cash, "shift {$shift->id}");
            $this->assertSame($shift->opening_float + $s['cash_sales'] - $s['cash_refunds'] + $s['cash_in'] - $s['cash_out'], $shift->expected_cash);
            $this->assertSame($shift->counted_cash - $shift->expected_cash, $shift->variance);
            $this->assertSame($shift->counted_cash - $shift->float_left, $shift->deposit_amount);
            $this->assertSame($shift->counted_cash, (int) $shift->cashCounts()->where('stage', CashCountStage::Closing)->sum('amount'));
            $this->assertSame($shift->opening_float, (int) $shift->cashCounts()->where('stage', CashCountStage::Opening)->sum('amount'));

            if ($shift->variance !== 0) {
                $this->assertNotEmpty($shift->variance_note);
            }

            if (abs($shift->variance) > 20000) {
                $this->assertNotNull($shift->variance_approved_by);
            }
        }
    }

    public function test_settlement_edc_sekali_per_hari_dari_shift_tutup_hari(): void
    {
        $this->seed(DemoSeeder::class);

        foreach (EdcSettlement::with('lines', 'shift')->get() as $settlement) {
            $this->assertTrue($settlement->shift->is_day_close);
            $edcTotal = (int) Payment::whereDate('business_date', $settlement->business_date->toDateString())->where('method_kind', 'edc')->sum('amount');
            $this->assertSame($edcTotal, (int) $settlement->lines->sum('pos_amount'));
            $this->assertFalse($settlement->hasDifference());
        }
    }

    public function test_ringkasan_pelanggan_sama_dengan_transaksi(): void
    {
        $this->seed(DemoSeeder::class);

        foreach (Customer::all() as $customer) {
            $paid = $customer->orders()->where('status', OrderStatus::Paid);
            $this->assertSame($paid->count(), $customer->visits_count);
            $this->assertSame((int) $paid->sum('total'), $customer->total_spent);
        }
    }

    public function test_tidak_diisi_dua_kali(): void
    {
        $this->seed(DemoSeeder::class);
        $count = Order::count();

        $this->seed(DemoSeeder::class);

        $this->assertSame($count, Order::count());
    }

    public function test_tidak_jalan_di_production(): void
    {
        $this->app['env'] = 'production';

        // db:seed di production butuh --force (tanpa itu seeder tidak jalan sama sekali).
        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(0, Order::count());
        $this->assertSame(0, User::count());
    }
}
