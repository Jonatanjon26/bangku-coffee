<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\ReasonType;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Reason;
use App\Models\Role;
use App\Support\OrderCorrections;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class OrdersAdminTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Data contoh hanya sampai "jam sekarang": tes dijalankan setelah tengah malam tidak punya transaksi hari ini.
        $this->travelTo(CarbonImmutable::parse('2026-10-19 21:30'));
        config(['admin.email' => null]);
        $this->seed(DemoSeeder::class);
    }

    private function reason(ReasonType $type): int
    {
        return Reason::ofType($type)->value('id');
    }

    public function test_daftar_dan_detail_transaksi(): void
    {
        $order = Order::where('status', OrderStatus::Paid)->latest('id')->first();
        $this->actingAs($this->staff('Kepala toko'));

        $this->get(OrderResource::getUrl('index'))->assertOk()->assertSee($order->code);
        Livewire::test(ManageOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->mountTableAction('view', $order)
            // Merender isi modal detail (menangkap error skema seperti metode yang tidak ada).
            ->assertMountedActionModalSee([$order->code, $order->items->first()->name, 'Rp '.number_format($order->total, 0, ',', '.')]);
    }

    public function test_filter_status(): void
    {
        $refunded = Order::where('status', OrderStatus::Refunded)->first();
        $paid = Order::where('status', OrderStatus::Paid)->first();
        $this->actingAs($this->owner());

        Livewire::test(ManageOrders::class)->filterTable('status', OrderStatus::Refunded->value)
            ->assertCanSeeTableRecords([$refunded])->assertCanNotSeeTableRecords([$paid]);
    }

    public function test_tanpa_hak_laporan_tidak_bisa_melihat(): void
    {
        $role = Role::firstWhere('name', 'Kepala toko');
        $role->syncPermissions([Permission::Admin, Permission::Menu]);

        $this->actingAs($this->staff('Kepala toko'))->get(OrderResource::getUrl('index'))->assertForbidden();
    }

    public function test_refund_utuh_dari_admin(): void
    {
        $order = Order::where('status', OrderStatus::Paid)->latest('id')->first();
        $manager = $this->staff('Kepala toko');
        $this->actingAs($manager);

        Livewire::test(ManageOrders::class)
            ->callTableAction('refund', $order, data: ['reason_id' => $this->reason(ReasonType::Refund), 'note' => 'komplain rasa'])
            ->assertHasNoTableActionErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $refund = $order->refunds()->sole();
        $this->assertSame([$order->total, $manager->id, $manager->id, 'komplain rasa'], [$refund->amount, $refund->refunded_by, $refund->approved_by, $refund->note]);
        $this->assertTrue(AuditLog::where('event', 'order.refunded')->where('auditable_id', $order->id)->where('user_id', $manager->id)->exists());
    }

    public function test_refund_perlu_hak_menyetujui(): void
    {
        $order = Order::where('status', OrderStatus::Paid)->whereDate('paid_business_date', today())->firstOrFail();
        $role = Role::firstWhere('name', 'Kepala toko');
        $role->syncPermissions([Permission::Admin, Permission::Reports]);
        $this->actingAs($this->staff('Kepala toko'));

        Livewire::test(ManageOrders::class)->assertTableActionHidden('refund', $order);

        app(Settings::class)->set('policy.refund_needs_approval', false);
        Livewire::test(ManageOrders::class)->assertTableActionVisible('refund', $order);
    }

    public function test_tidak_ada_refund_hari_berikutnya(): void
    {
        $old = Order::where('status', OrderStatus::Paid)->whereDate('paid_business_date', '<', today())->firstOrFail();
        $this->actingAs($this->owner());

        Livewire::test(ManageOrders::class)->assertTableActionHidden('refund', $old);
        $this->assertFalse(auth()->user()->can('refund', $old));

        // Juga ditolak di service (bukan hanya tombol disembunyikan).
        $this->expectException(ValidationException::class);
        app(OrderCorrections::class)->refund($old, auth()->user(), auth()->user(), $this->reason(ReasonType::Refund), null, null);
    }

    public function test_refund_hanya_untuk_yang_dibayar(): void
    {
        $refunded = Order::where('status', OrderStatus::Refunded)->first();
        $this->actingAs($this->owner());

        Livewire::test(ManageOrders::class)->assertTableActionHidden('refund', $refunded);
    }

    public function test_batal_open_bill_dari_admin(): void
    {
        $paid = Order::where('status', OrderStatus::Paid)->first();
        $open = $paid->replicate(['code', 'queue_number']);
        $open->forceFill(['code' => 'B-TEST-001', 'queue_number' => 999, 'status' => OrderStatus::Open, 'paid_at' => null, 'paid_by' => null, 'paid_shift_id' => null, 'paid_business_date' => null])->save();
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(ManageOrders::class)
            ->assertTableActionHidden('void', $paid)
            ->callTableAction('void', $open, data: ['reason_id' => $this->reason(ReasonType::Void)])
            ->assertHasNoTableActionErrors();

        $open->refresh();
        $this->assertSame([OrderStatus::Voided, $owner->id], [$open->status, $open->voided_by]);
        $this->assertNotNull($open->void_reason_label);
    }

    public function test_transaksi_tidak_bisa_dibuat_diubah_atau_dihapus(): void
    {
        $order = Order::first();
        $this->actingAs($this->owner());

        $this->assertFalse(auth()->user()->can('create', Order::class));
        $this->assertFalse(auth()->user()->can('update', $order));
        $this->assertFalse(auth()->user()->can('delete', $order));
    }
}
