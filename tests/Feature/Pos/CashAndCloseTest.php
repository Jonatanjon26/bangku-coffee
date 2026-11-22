<?php

namespace Tests\Feature\Pos;

use App\Enums\CashCountStage;
use App\Enums\CashMovementType;
use App\Enums\ReasonType;
use App\Livewire\Pos\Kasir;
use App\Models\AuditLog;
use App\Models\Bank;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\EdcSettlement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Support\OrderCorrections;
use App\Support\Printing\Documents;
use App\Support\SalesReport;
use App\Support\ShiftService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

class CashAndCloseTest extends PosTestCase
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
    }

    private function kasir(string $name = 'Rani')
    {
        $this->signIn($this->user($name), $this->device, $this->token);

        return Livewire::withCookies([$this->cookieName() => $this->token])->test(Kasir::class);
    }

    private function reason(ReasonType $type, string $label): int
    {
        return Reason::ofType($type)->firstWhere('label', $label)->id;
    }

    /** Jual tunai 28.000 dan QRIS 20.000. */
    private function sell(): void
    {
        $kasir = $this->kasir();
        $kasir->call('addLine', MenuItem::firstWhere('name', 'Croissant Butter')->id)->call('addLine', MenuItem::firstWhere('name', 'Air Mineral')->id)
            ->set('customerName', 'Ana')->call('pay', PaymentMethod::firstWhere('name', 'Tunai')->id, null, 28000);
        $kasir->call('addLine', MenuItem::firstWhere('name', 'Americano')->id)->set('customerName', 'Budi')->call('pay', PaymentMethod::firstWhere('name', 'QRIS')->id);
    }

    /* ---------------------------------------------------------------- kas masuk / keluar */

    public function test_kas_masuk_dan_keluar_dicatat_dan_masuk_hitungan_laci(): void
    {
        $kasir = $this->kasir();
        $kasir->call('addCash', 'out', 20000, $this->reason(ReasonType::CashOut, 'Es batu'), 'dua karung')->assertDispatched('cash-done');
        $kasir->call('addCash', 'in', 50000, $this->reason(ReasonType::CashIn, 'Tambah uang receh'))->assertDispatched('cash-done');

        $out = CashMovement::where('type', CashMovementType::Out)->sole();
        $this->assertSame(['Es batu - dua karung', 20000, $this->user('Rani')->id, null], [$out->reason_label, $out->amount, $out->user_id, $out->approved_by]);
        $this->assertSame(300000 - 20000 + 50000, $this->shift->summarize()['expected']);
        $this->assertTrue(AuditLog::where('event', 'cash.out')->where('auditable_id', $out->id)->exists());
    }

    public function test_kas_keluar_di_atas_batas_perlu_pin_penyetuju(): void
    {
        $kasir = $this->kasir();
        $galon = $this->reason(ReasonType::CashOut, 'Galon');

        $kasir->call('addCash', 'out', 150000, $galon)->assertDispatched('cash-failed');
        $kasir->call('addCash', 'out', 150000, $galon, '', '1111')->assertDispatched('cash-failed');
        $this->assertSame(0, CashMovement::count());

        $kasir->call('addCash', 'out', 150000, $galon, '', '3333')->assertDispatched('cash-done');
        $this->assertSame($this->user('Sinta')->id, CashMovement::sole()->approved_by);
        $this->assertSame($this->user('Sinta')->id, AuditLog::firstWhere('event', 'cash.out')->approved_by);
    }

    public function test_kas_keluar_tidak_bisa_dipecah_untuk_menghindari_persetujuan(): void
    {
        $this->setting('policy.cash_out_shift_approval_above', 300000);
        $kasir = $this->kasir();
        $galon = $this->reason(ReasonType::CashOut, 'Galon');

        // 3 × Rp 100.000 = batas per shift (tidak di atasnya) → tanpa PIN.
        foreach (range(1, 3) as $i) {
            $kasir->call('addCash', 'out', 100000, $galon)->assertDispatched('cash-done');
        }

        // Rp 50.000 lagi: di bawah batas per transaksi, tetapi total shift jadi Rp 350.000 → perlu PIN.
        $kasir->call('addCash', 'out', 50000, $galon)->assertDispatched('cash-failed');
        $this->assertSame(3, CashMovement::count());
        $kasir->call('addCash', 'out', 50000, $galon, '', '3333')->assertDispatched('cash-done');
        $this->assertSame($this->user('Sinta')->id, CashMovement::latest('id')->first()->approved_by);

        // Batas per shift 0 = mati.
        $this->setting('policy.cash_out_shift_approval_above', 0);
        $this->kasir()->call('addCash', 'out', 50000, $galon)->assertDispatched('cash-done');
    }

    public function test_kas_alasan_lainnya_wajib_keterangan_dan_jumlah_valid(): void
    {
        $kasir = $this->kasir();
        $lainnya = $this->reason(ReasonType::CashOut, 'Lainnya');

        $kasir->call('addCash', 'out', 10000, $lainnya)->assertDispatched('cash-failed');
        $kasir->call('addCash', 'out', 0, $this->reason(ReasonType::CashOut, 'Gas'))->assertDispatched('cash-failed');
        $kasir->call('addCash', 'out', 10000, $this->reason(ReasonType::CashIn, 'Titipan owner'))->assertDispatched('cash-failed'); // alasan daftar lain
        $kasir->call('addCash', 'out', 10000, $lainnya, 'beli sabun')->assertDispatched('cash-done');

        $this->assertSame('beli sabun', CashMovement::sole()->reason_label);
    }

    /* ---------------------------------------------------------------- tutup shift */

    public function test_hitung_buta_pratinjau_dari_server(): void
    {
        $this->sell();

        $preview = $this->kasir()->instance()->closingPreview(app(ShiftService::class), [100000 => 3, 20000 => 1, 5000 => 1], 3000, false);

        $this->assertSame(328000, $preview['summary']['expected']);
        $this->assertSame(328000, $preview['counted']);
        $this->assertSame(0, $preview['variance']);
        $this->assertSame(1, $preview['cash_sales_count']);
    }

    public function test_tutup_shift_menyimpan_hitungan_selisih_modal_dan_setoran(): void
    {
        $this->sell();
        $kasir = $this->kasir();
        $input = ['counts' => [100000 => 3, 20000 => 1], 'coins' => 3000, 'float_left' => 200000, 'variance_note' => '', 'day_close' => false];

        $kasir->call('closeShift', $input)->assertDispatched('close-failed', message: __('pos.close.errors.variance_note'));
        $kasir->call('closeShift', array_merge($input, ['float_left' => 999999, 'variance_note' => 'x']))->assertDispatched('close-failed', message: __('pos.close.errors.float_left'));
        $this->assertTrue($this->shift->fresh()->isOpen());

        $kasir->call('closeShift', array_merge($input, ['variance_note' => 'salah beri kembalian']))->assertDispatched('shift-closed');

        $s = $this->shift->fresh();
        $this->assertFalse($s->isOpen());
        $this->assertSame([328000, 323000, -5000, 'salah beri kembalian', 200000, 123000], [$s->expected_cash, $s->counted_cash, $s->variance, $s->variance_note, $s->float_left, $s->deposit_amount]);
        $this->assertNull($s->variance_approved_by);
        $this->assertSame($this->user('Rani')->id, $s->closed_by);
        $this->assertSame(48000, $s->summary['total']);
        $this->assertSame(323000, (int) $s->cashCounts()->where('stage', CashCountStage::Closing)->sum('amount'));
        $this->assertTrue(AuditLog::where('event', 'shift.variance')->where('auditable_id', $s->id)->exists());

        // Shift berikutnya: serah terima dari modal yang ditinggal.
        $this->kasir('Dimas')->assertSee('id="open-dlg"', escape: false)->assertSee('Rp 200.000');
        $next = app(ShiftService::class)->open($this->user('Dimas'), $this->device, ShiftTemplate::firstWhere('name', 'Sore')->id, [100000 => 2], 0, null);
        $this->assertSame([200000, $s->id], [$next->handover_expected, $next->previous_shift_id]);
    }

    public function test_hitung_ulang_setelah_melihat_selisih_tercatat_di_audit(): void
    {
        $kasir = $this->kasir();
        // Hitungan pertama Rp 0 (mengintip angka seharusnya), lalu hitungan "pas".
        $kasir->call('closingPreview', [], 0)->call('closingPreview', [100000 => 3], 0)
            ->call('closeShift', ['counts' => [100000 => 3], 'float_left' => 300000])->assertDispatched('shift-closed');

        $log = AuditLog::firstWhere('event', 'shift.recounted');
        $this->assertNotNull($log);
        $this->assertSame(0, $log->old_values['counted']);
        $this->assertSame(300000, $log->new_values['counted']);
        $this->assertSame(2, $log->new_values['previews']);
    }

    public function test_hitungan_tidak_berubah_tidak_dicatat_sebagai_hitung_ulang(): void
    {
        $this->kasir()->call('closingPreview', [100000 => 3], 0)
            ->call('closeShift', ['counts' => [100000 => 3], 'float_left' => 300000])->assertDispatched('shift-closed');

        $this->assertFalse(AuditLog::where('event', 'shift.recounted')->exists());
    }

    public function test_ringkasan_shift_bersih_setelah_refund_sama_dengan_laporan(): void
    {
        $this->sell(); // Tunai 28.000 + QRIS 20.000
        $qris = Order::where('customer_name', 'Budi')->sole();
        app(OrderCorrections::class)->refund($qris, $this->user('Rani'), $this->user('Sinta'), $this->reason(ReasonType::Refund, 'Salah input'), null, $this->shift);

        $sum = $this->shift->fresh()->summarize();
        $this->assertSame([48000, 20000, 28000], [$sum['total'], $sum['refunds'], $sum['net']]);
        $this->assertSame(['QRIS' => 20000], $sum['refunds_by_method']);

        // Sama dengan omzet hari itu di Laporan/Dasbor.
        $day = CarbonImmutable::parse('2026-10-19');
        $this->assertSame($sum['net'], app(SalesReport::class)->totals($day, $day)['total']);

        // Laporan shift 57 mm mencetak baris refund & total bersih.
        $text = collect(app(Documents::class)->shiftReport($this->shift->fresh())['lines'])->pluck('text')->implode("\n");
        $this->assertStringContainsString(__('pos.print.refund_method', ['method' => 'QRIS']), $text);
        $this->assertMatchesRegularExpression('/'.preg_quote(__('pos.print.total_net'), '/').' +28\.000/', $text);
    }

    public function test_selisih_di_atas_batas_perlu_pin(): void
    {
        $kasir = $this->kasir();
        $input = ['counts' => [100000 => 2], 'coins' => 0, 'float_left' => 100000, 'variance_note' => 'uang hilang', 'day_close' => false];

        $kasir->call('closeShift', $input)->assertDispatched('close-failed');
        $kasir->call('closeShift', $input, '2222')->assertDispatched('close-failed');
        $kasir->call('closeShift', $input, '9999')->assertDispatched('shift-closed');

        $this->assertSame($this->user('Owner')->id, $this->shift->fresh()->variance_approved_by);
    }

    public function test_open_bill_dialihkan_dan_lewat_hari_perlu_pin(): void
    {
        $this->kasir()->call('addLine', MenuItem::firstWhere('name', 'Americano')->id)->set('customerName', 'Cici')->call('hold');
        $order = Order::sole();
        $input = ['counts' => [100000 => 3], 'coins' => 0, 'float_left' => 300000, 'variance_note' => ''];

        // Bukan tutup hari: dialihkan tanpa PIN.
        $this->assertFalse($this->kasir()->instance()->closingPreview(app(ShiftService::class), [100000 => 3], 0, false)['carry_needs_approval']);

        // Tutup hari: perlu PIN.
        $kasir = $this->kasir();
        $kasir->call('closeShift', $input + ['day_close' => true, 'edc' => ['QRIS' => 0]])->assertDispatched('close-failed');
        $kasir->call('closeShift', $input + ['day_close' => true, 'edc' => ['QRIS' => 0]], '3333')->assertDispatched('shift-closed');

        $s = $this->shift->fresh();
        $this->assertSame([1, 20000, $this->user('Sinta')->id], [$s->carried_open_bills_count, $s->carried_open_bills_total, $s->open_bills_approved_by]);
        $this->assertSame([$order->id], $s->carriedOpenBills->pluck('id')->all());
        $this->assertSame($this->user('Sinta')->id, $order->fresh()->day_carry_approved_by);
        $this->assertTrue(AuditLog::where('event', 'open_bills.carried_over_day')->exists());
    }

    public function test_tidak_bisa_tutup_dengan_open_bill_bila_pengalihan_dimatikan(): void
    {
        $this->setting('policy.carry_open_bills', false);
        $this->kasir()->call('addLine', MenuItem::firstWhere('name', 'Americano')->id)->set('customerName', 'Cici')->call('hold');

        $this->kasir()->call('closeShift', ['counts' => [100000 => 3], 'float_left' => 300000])->assertDispatched('close-failed', message: __('pos.close.errors.open_bills'));
        $this->assertTrue($this->shift->fresh()->isOpen());
    }

    public function test_tutup_hari_mencocokkan_settlement_edc(): void
    {
        $this->sell();
        PaymentMethod::where('name', 'Kartu kredit')->update(['is_active' => true]);
        $bca = Bank::firstWhere('name', 'BCA');
        $this->kasir()->call('addLine', MenuItem::firstWhere('name', 'Cappuccino')->id)->set('customerName', 'Dedi')->call('pay', PaymentMethod::firstWhere('name', 'Kartu kredit')->id, $bca->id);

        $preview = $this->kasir()->instance()->closingPreview(app(ShiftService::class), [], 0, true);
        $this->assertEqualsCanonicalizing([['QRIS', 20000], ['Kartu kredit BCA', 25000]], array_map(fn ($r) => [$r['label'], $r['pos_amount']], $preview['edc']));

        $input = ['counts' => [100000 => 3, 20000 => 1, 5000 => 1], 'coins' => 3000, 'float_left' => 300000, 'variance_note' => '', 'day_close' => true];
        $kasir = $this->kasir();
        $kasir->call('closeShift', $input + ['edc' => ['QRIS' => 20000]])->assertDispatched('close-failed', message: __('pos.close.errors.edc_missing', ['label' => 'Kartu kredit BCA']));
        $kasir->call('closeShift', $input + ['edc' => ['QRIS' => 20000, 'Kartu kredit BCA' => 20000]])->assertDispatched('close-failed', message: __('pos.close.errors.edc_note'));
        $kasir->call('closeShift', $input + ['edc' => ['QRIS' => 20000, 'Kartu kredit BCA' => 20000], 'edc_note' => 'satu transaksi belum masuk'])->assertDispatched('shift-closed');

        $settlement = EdcSettlement::with('lines')->sole();
        $this->assertSame('2026-10-19', $settlement->business_date->toDateString());
        $this->assertSame($this->shift->id, $settlement->shift_id);
        $this->assertSame(-5000, $settlement->lines->firstWhere('label', 'Kartu kredit BCA')->difference);
        $this->assertTrue($this->shift->fresh()->is_day_close);
        $this->assertTrue(AuditLog::where('event', 'edc.difference')->exists());
    }

    public function test_refund_edc_hari_yang_sama_tidak_ikut_settlement(): void
    {
        $this->sell();
        $qris = Order::where('customer_name', 'Budi')->sole();
        app(OrderCorrections::class)->refund($qris, $this->user('Rani'), $this->user('Sinta'), $this->reason(ReasonType::Refund, 'Salah input'), null, $this->shift);

        $preview = $this->kasir()->instance()->closingPreview(app(ShiftService::class), [], 0, true);
        $this->assertSame([['QRIS', 0]], array_map(fn ($r) => [$r['label'], $r['pos_amount']], $preview['edc']));
    }

    public function test_peringatan_settlement_edc_yang_terlewat(): void
    {
        // 19/10: QRIS terjual, shift ditutup tanpa "tutup hari" → belum dicocokkan.
        $this->sell();
        $this->kasir()->call('closeShift', ['counts' => [100000 => 3, 20000 => 1, 5000 => 1], 'coins' => 3000, 'float_left' => 300000, 'variance_note' => '', 'day_close' => false])
            ->assertDispatched('shift-closed');
        $report = app(SalesReport::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00'));
        $this->assertSame(['2026-10-19'], array_map(fn ($d) => $d->toDateString(), $report->unsettledEdcDates(CarbonImmutable::parse('2026-10-20'))));
        $this->assertSame('2026-10-19', $report->dashboard()['edc_unsettled'][0]->toDateString());

        // Hari ini sendiri tidak diperingatkan (belum waktunya tutup hari).
        $this->assertSame([], $report->unsettledEdcDates(CarbonImmutable::parse('2026-10-19')));

        // Setelah ada settlement tanggal itu → peringatan hilang.
        EdcSettlement::forceCreate(['business_date' => '2026-10-19', 'shift_id' => $this->shift->id, 'settled_by' => $this->user('Sinta')->id]);
        $this->assertSame([], $report->unsettledEdcDates(CarbonImmutable::parse('2026-10-20')));
    }

    public function test_edc_yang_semua_direfund_hari_itu_tidak_diperingatkan(): void
    {
        $this->sell();
        $qris = Order::where('customer_name', 'Budi')->sole();
        app(OrderCorrections::class)->refund($qris, $this->user('Rani'), $this->user('Sinta'), $this->reason(ReasonType::Refund, 'Salah input'), null, $this->shift);

        $this->assertSame([], app(SalesReport::class)->unsettledEdcDates(CarbonImmutable::parse('2026-10-20')));
    }

    public function test_settlement_edc_sekali_sehari(): void
    {
        $this->kasir()->call('closeShift', ['counts' => [100000 => 3], 'float_left' => 300000, 'day_close' => true, 'edc' => ['QRIS' => 0]])->assertDispatched('shift-closed');
        $second = app(ShiftService::class)->open($this->user('Dimas'), $this->device, ShiftTemplate::firstWhere('name', 'Sore')->id, [100000 => 3], 0, null);

        $preview = $this->kasir('Dimas')->instance()->closingPreview(app(ShiftService::class), [100000 => 3], 0, true);
        $this->assertTrue($preview['edc_settled']);

        $this->kasir('Dimas')->call('closeShift', ['counts' => [100000 => 3], 'float_left' => 300000, 'day_close' => true])->assertDispatched('shift-closed');
        $this->assertSame(1, EdcSettlement::count());
        $this->assertFalse($second->fresh()->isOpen());
    }

    public function test_shift_terakhir_disarankan_tutup_hari(): void
    {
        $this->assertFalse(app(ShiftService::class)->isLastShift($this->shift));

        $this->shift->forceFill(['closed_at' => now(), 'closed_by' => $this->user('Rani')->id, 'float_left' => 0])->save();
        $sore = app(ShiftService::class)->open($this->user('Rani'), $this->device, ShiftTemplate::firstWhere('name', 'Sore')->id, [], 0, null);
        $this->assertTrue(app(ShiftService::class)->isLastShift($sore));
    }

    public function test_ringkasan_shift_tampil_tanpa_angka_tunai(): void
    {
        $this->sell();
        $this->kasir()->call('addCash', 'out', 20000, $this->reason(ReasonType::CashOut, 'Gas'));

        // Hitung buta: total (Rp 48.000) & baris Tunai tidak tampil; EDC (QRIS) tetap untuk cek settlement.
        $this->kasir()->assertSee(__('pos.shift_info.title', ['name' => 'Pagi', 'cashier' => 'Rani']))
            ->assertDontSee('Rp 48.000')
            ->assertDontSeeHtml('<span>Tunai</span><b>')
            ->assertSeeHtml('<span>QRIS</span><b>Rp 20.000</b>')
            ->assertSee(__('pos.shift_info.blind_note'))
            ->assertSee('Gas')
            ->assertSee('−Rp 20.000');
    }
}
