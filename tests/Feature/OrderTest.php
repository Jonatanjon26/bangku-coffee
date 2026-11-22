<?php

namespace Tests\Feature;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderSequence;
use App\Models\OrderType;
use App\Models\Shift;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\ConfigSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_nomor_antrean_harian_bertambah_dan_mulai_lagi_besok(): void
    {
        $today = CarbonImmutable::parse('2026-10-19');

        $this->assertSame(1, OrderSequence::next($today));
        $this->assertSame(2, OrderSequence::next($today));
        $this->assertSame(1, OrderSequence::next($today->addDay()));
        $this->assertSame(3, OrderSequence::next($today->setTime(21, 30)));
    }

    public function test_kode_pesanan_memakai_prefiks_dari_pengaturan(): void
    {
        $date = CarbonImmutable::parse('2026-10-19');

        $this->assertSame('B-1019-007', Order::makeCode($date, 7));

        app(Settings::class)->set('orders.code_prefix', 'BK');
        $this->assertSame('BK-1019-120', Order::makeCode($date, 120));
    }

    /** Regresi: cast "date" bawaan menyimpan jam di SQLite sehingga index unik per tanggal kebobolan. */
    public function test_tanggal_bisnis_disimpan_tanpa_jam_dan_unik(): void
    {
        $this->seed(ConfigSeeder::class);
        $order = $this->makeOrder(CarbonImmutable::parse('2026-10-19 08:15'), 1);

        $this->assertSame('2026-10-19', DB::table('orders')->where('id', $order->id)->value('business_date'));
        $this->assertSame('2026-10-19', $order->fresh()->business_date->toDateString());

        OrderSequence::next(CarbonImmutable::parse('2026-10-19 10:00'));
        OrderSequence::updateOrCreate(['business_date' => '2026-10-19'], ['last_number' => 5]);
        $this->assertSame(1, OrderSequence::count());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeOrder(CarbonImmutable::parse('2026-10-19 20:00'), 1);
    }

    public function test_total_dihitung_server_dengan_service_hanya_untuk_jenis_pesanan_yang_kena(): void
    {
        $this->seed(ConfigSeeder::class);
        $settings = app(Settings::class);
        $settings->set('charges.service_enabled', true);
        $settings->set('charges.tax_enabled', true);

        $dine = $this->makeOrder(CarbonImmutable::parse('2026-10-19'), 1, 'Di tempat');
        $dine->forceFill(['discount_type' => DiscountType::Percent, 'discount_value' => 10]);
        $totals = $dine->recalculate();

        // 2 × 25.000 + 20.000 = 70.000; −10% = 63.000; service 5% = 3.150; PB1 10% = 6.615.
        $this->assertSame(70000, $totals->subtotal);
        $this->assertSame(7000, $dine->discount_amount);
        $this->assertSame(3150, $dine->service_amount);
        $this->assertSame(6615, $dine->tax_amount);
        $this->assertSame(72765, $dine->total);

        $take = $this->makeOrder(CarbonImmutable::parse('2026-10-19'), 2, 'Bawa pulang');
        $take->recalculate();
        $this->assertSame(0, $take->service_amount);
        $this->assertSame(7000, $take->tax_amount);
        $this->assertSame(77000, $take->total);
    }

    public function test_status_dan_total_tidak_bisa_diisi_massal(): void
    {
        $order = new Order(['status' => OrderStatus::Paid, 'total' => 1, 'discount_approved_by' => 1, 'customer_name' => 'Ana']);

        $this->assertNull($order->status);
        $this->assertNull($order->total);
        $this->assertNull($order->discount_approved_by);
        $this->assertSame('Ana', $order->customer_name);
    }

    private function makeOrder(CarbonImmutable $date, int $queue, string $typeName = 'Di tempat'): Order
    {
        $cashier = User::factory()->create();
        $shift = new Shift(['name' => 'Pagi', 'business_date' => $date, 'opening_float' => 300000]);
        $shift->forceFill(['opened_by' => $cashier->id, 'opened_at' => $date])->save();
        $type = OrderType::firstWhere('name', $typeName);

        $order = new Order(['customer_name' => 'Ana', 'order_type_id' => $type->id, 'order_type_name' => $type->name]);
        $order->forceFill([
            'code' => Order::makeCode($date, $queue).'-'.uniqid(),
            'business_date' => $date,
            'queue_number' => $queue,
            'status' => OrderStatus::Open,
            'created_by' => $cashier->id,
            'opened_shift_id' => $shift->id,
        ])->save();

        $order->items()->create(['name' => 'Cappuccino', 'base_price' => 25000, 'unit_price' => 25000, 'quantity' => 2]);
        $order->items()->create(['name' => 'Americano', 'base_price' => 20000, 'unit_price' => 20000, 'quantity' => 1]);

        return $order;
    }
}
