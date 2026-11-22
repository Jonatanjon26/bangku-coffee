<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Models\AuditLog;
use App\Models\EdcSettlement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Shift;
use App\Support\SalesReport;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Livewire\Livewire;

class ReportsAdminTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 21:30'));
        config(['admin.email' => null]);
        $this->seed(DemoSeeder::class);
    }

    private function noReportsManager()
    {
        Role::firstWhere('name', 'Kepala toko')->syncPermissions([Permission::Admin, Permission::Menu]);

        return $this->staff('Kepala toko');
    }

    /* ---------------------------------------------------------------- angka laporan */

    public function test_dasbor_memperingatkan_settlement_edc_yang_terlewat(): void
    {
        $this->actingAs($this->owner());
        Livewire::test(Dashboard::class)->assertDontSee(__('admin.dashboard.edc_unsettled_hint'));

        // Settlement 17/10 tidak ada (tutup hari terlewat) → peringatan dengan tanggalnya.
        EdcSettlement::whereDate('business_date', '2026-10-17')->get()->each(fn ($s) => [$s->lines()->delete(), $s->delete()]);

        Livewire::test(Dashboard::class)->assertSee(__('admin.dashboard.edc_unsettled', ['dates' => '17 Okt']))
            ->assertSee(__('admin.dashboard.edc_unsettled_hint'));
    }

    public function test_omzet_tanpa_refund_dan_dihitung_pada_tanggal_bayar(): void
    {
        $report = app(SalesReport::class);
        [$from, $to] = SalesReport::range('30');
        $totals = $report->totals($from, $to);

        $paid = Order::where('status', 'paid')->sum('total');
        $this->assertSame((int) $paid, $totals['total']);
        $this->assertSame(Order::where('status', 'paid')->count(), $totals['count']);
        $this->assertSame((int) Refund::sum('amount'), $totals['refunds']);
        $this->assertGreaterThan(0, $totals['refund_count']);

        // Semua rincian menjumlah ke omzet (metode, kasir, jenis); kategori & menu = harga item.
        $this->assertSame($totals['total'], array_sum($report->byMethod($from, $to)));
        $this->assertSame($totals['total'], array_sum($report->byCashier($from, $to)));
        $this->assertSame($totals['total'], array_sum($report->byOrderType($from, $to)));
        $this->assertSame(array_sum($report->byCategory($from, $to)), array_sum(array_column($report->items($from, $to), 'total')));
        $this->assertSame($totals['total'], array_sum($report->hourly($from, $to)));
        $this->assertSame($totals['total'], array_sum(array_column($report->daily($from, $to), 'value')));
        $this->assertCount(30, $report->daily($from, $to));
    }

    public function test_rentang_waktu(): void
    {
        $now = CarbonImmutable::parse('2026-10-19 10:00');

        $this->assertSame(['2026-10-19', '2026-10-19'], array_map(fn ($d) => $d->toDateString(), SalesReport::range('today', $now)));
        $this->assertSame(['2026-10-13', '2026-10-19'], array_map(fn ($d) => $d->toDateString(), SalesReport::range('7', $now)));
        $this->assertSame(['2026-10-01', '2026-10-19'], array_map(fn ($d) => $d->toDateString(), SalesReport::range('month', $now)));
    }

    public function test_open_bill_belum_dihitung_sampai_dibayar(): void
    {
        $order = Order::where('status', 'paid')->whereDate('paid_business_date', '2026-10-19')->first();
        $before = app(SalesReport::class)->totals(CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-19'))['total'];
        $order->forceFill(['status' => 'open'])->save();

        $this->assertSame($before - $order->total, app(SalesReport::class)->totals(CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-19'))['total']);
    }

    /* ---------------------------------------------------------------- halaman */

    public function test_dasbor_untuk_hak_laporan(): void
    {
        $today = app(SalesReport::class)->totals(CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-19'));

        $this->actingAs($this->staff('Kepala toko'))->get(Dashboard::getUrl())->assertOk()
            ->assertSee(__('admin.dashboard.sales_today'))
            ->assertSee('Rp '.number_format($today['total'], 0, ',', '.'))
            ->assertSee(__('admin.dashboard.top'));
    }

    public function test_dasbor_tanpa_hak_laporan_tidak_menampilkan_angka(): void
    {
        $this->actingAs($this->noReportsManager())->get(Dashboard::getUrl())->assertOk()
            ->assertDontSee(__('admin.dashboard.sales_today'));
    }

    public function test_laporan_dan_rentang(): void
    {
        $this->actingAs($this->owner());
        $topItem = app(SalesReport::class)->items(...SalesReport::range('30'))[0]['name'];

        Livewire::test(Reports::class)->assertSee(__('admin.reports.by_category'))
            ->call('setRange', '30')->assertSet('range', '30')->assertSee($topItem)
            ->call('setRange', 'semua')->assertSet('range', '7');
    }

    public function test_laporan_butuh_hak_laporan(): void
    {
        $this->actingAs($this->noReportsManager())->get(Reports::getUrl())->assertForbidden();
        $this->actingAs($this->noReportsManager())->get(ShiftResource::getUrl('index'))->assertForbidden();
    }

    public function test_unduh_csv_menu_aman_dari_formula(): void
    {
        MenuItem::first()->update(['name' => '=HYPERLINK("http://x")']);
        $order = Order::where('status', 'paid')->latest('id')->first();
        $order->items()->first()->update(['name' => '=HYPERLINK("http://x")']);
        $this->actingAs($this->owner());

        $response = Livewire::test(Reports::class)->call('setRange', '30')->instance()->downloadCsv();
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Menu;Kategori;Terjual;Omzet', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString("\n\"=HYPERLINK", $csv);
    }

    /* ---------------------------------------------------------------- shift & kas */

    public function test_setoran_tidak_bisa_diterima_oleh_penyetor_sendiri(): void
    {
        $manager = $this->staff('Kepala toko');
        $shift = Shift::whereNotNull('closed_at')->latest('closed_at')->first();
        $shift->forceFill(['closed_by' => $manager->id])->save();
        $this->actingAs($manager);

        $this->assertFalse($manager->can('receiveDeposit', $shift));
        Livewire::test(ManageShifts::class)->assertTableActionHidden('receive', $shift);
        $this->assertNull($shift->fresh()->deposit_received_at);

        // Super admin tetap boleh (pemilik toko).
        $owner = $this->owner();
        $shift->forceFill(['closed_by' => $owner->id])->save();
        $this->assertTrue($owner->can('receiveDeposit', $shift));
    }

    public function test_laporan_shift_butuh_akses_panel(): void
    {
        $shift = Shift::whereNotNull('closed_at')->first();
        $user = $this->staff('Kepala toko');
        $this->actingAs($user)->get(route('admin.shift-report', $shift))->assertOk();

        // Hak "admin" (masuk panel) dicabut, hak laporan masih ada → ditolak.
        $user->role->syncPermissions([Permission::Reports]);
        $this->actingAs($user->fresh())->get(route('admin.shift-report', $shift))->assertForbidden();
    }

    public function test_daftar_shift_dan_tandai_setoran_diterima(): void
    {
        $shift = Shift::whereNotNull('closed_at')->latest('closed_at')->first();
        $manager = $this->staff('Kepala toko');
        $this->actingAs($manager);

        Livewire::test(ManageShifts::class)
            ->assertCanSeeTableRecords([$shift])
            ->callTableAction('receive', $shift)
            ->assertHasNoTableActionErrors();

        $shift->refresh();
        $this->assertSame($manager->id, $shift->deposit_received_by);
        $this->assertNotNull($shift->deposit_received_at);
        $this->assertTrue(AuditLog::where('event', 'shift.deposit_received')->where('auditable_id', $shift->id)->exists());

        Livewire::test(ManageShifts::class)->assertTableActionHidden('receive', $shift);
    }

    public function test_tandai_setoran_perlu_hak_menyetujui(): void
    {
        $shift = Shift::whereNotNull('closed_at')->first();
        Role::firstWhere('name', 'Kepala toko')->syncPermissions([Permission::Admin, Permission::Reports]);
        $this->actingAs($this->staff('Kepala toko'));

        $this->assertFalse(auth()->user()->can('receiveDeposit', $shift));
        Livewire::test(ManageShifts::class)->assertTableActionHidden('receive', $shift);
    }

    public function test_laporan_shift_untuk_dicetak(): void
    {
        $shift = Shift::whereNotNull('closed_at')->latest('closed_at')->first();
        $url = route('admin.shift-report', $shift);

        $this->get($url)->assertRedirect(route('filament.admin.auth.login'));
        $this->actingAs($this->noReportsManager())->get($url)->assertForbidden();

        $this->actingAs($this->owner())->get($url)->assertOk()
            ->assertSee('LAPORAN SHIFT')
            ->assertSee('Shift '.$shift->name)
            ->assertSee(__('admin.shifts.print'));

        // Shift yang masih berjalan belum punya laporan.
        $shift->forceFill(['closed_at' => null])->save();
        $this->actingAs($this->owner())->get($url)->assertForbidden();
    }

    public function test_laporan_bulanan_dapat_memilih_bulan_lain(): void
    {
        $this->actingAs($this->owner());

        [$from, $to] = SalesReport::range('month', month: '2026-09');
        $this->assertSame('2026-09-01', $from->toDateString());
        $this->assertSame('2026-09-30', $to->toDateString());

        Livewire::test(Reports::class)
            ->call('setRange', 'month')
            ->assertSeeHtml('id="report-month"')
            ->set('month', '2026-09')
            ->assertSee(__('admin.reports.empty_period'));
    }
}
