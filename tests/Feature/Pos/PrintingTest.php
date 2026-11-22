<?php

namespace Tests\Feature\Pos;

use App\Enums\ReasonType;
use App\Livewire\Pos\Kasir;
use App\Models\Bank;
use App\Models\Device;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Support\Printing\Documents;
use App\Support\ShiftService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

class PrintingTest extends PosTestCase
{
    private Device $device;

    private string $token;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:30'));
        [$this->device, $this->token] = $this->tablet();
        $this->shift = app(ShiftService::class)->open($this->user('Rani'), $this->device, ShiftTemplate::firstWhere('name', 'Pagi')->id, [100000 => 3], 0, null);
    }

    private function kasir()
    {
        $this->signIn($this->user('Rani'), $this->device, $this->token);

        return Livewire::withCookies([$this->cookieName() => $this->token])->test(Kasir::class);
    }

    private function id(string $item): int
    {
        return MenuItem::firstWhere('name', $item)->id;
    }

    private function text(array $doc): string
    {
        return implode("\n", array_column($doc['lines'], 'text'));
    }

    /** Ambil job cetak dari event Livewire terakhir dengan nama $event. */
    private function job($component, string $event): ?array
    {
        $dispatches = collect($component->effects['dispatches'] ?? [])->where('name', $event)->values();

        return $dispatches->last()['params']['job'] ?? null;
    }

    private function payKopi($kasir, bool $receipt = true)
    {
        $size = ModifierGroup::firstWhere('name', 'Ukuran');
        $sugar = ModifierGroup::firstWhere('name', 'Gula');

        return $kasir->call('addLine', $this->id('Kopi Susu Bangku'), [$size->id => $size->options()->where('name', 'Large')->value('id'), $sugar->id => $sugar->options()->where('name', 'Kurang manis')->value('id')], 2, 'es sedikit')
            ->call('addLine', $this->id('Croissant Butter'))
            ->set('customerName', 'Gita')
            ->call('setTable', '5')
            ->call('pay', PaymentMethod::firstWhere('name', 'Tunai')->id, null, 100000, $receipt);
    }

    public function test_bayar_mencetak_tiket_lalu_struk(): void
    {
        $job = $this->job($this->payKopi($this->kasir()), 'paid');

        $this->assertCount(2, $job['docs']);
        [$ticket, $receipt] = $job['docs'];
        $this->assertSame(32, $job['cols']);

        $t = $this->text($ticket);
        $this->assertStringContainsString('TIKET BARISTA', $t);
        $this->assertStringNotContainsString('TAMBAHAN', $t);
        $this->assertStringContainsString('#1', $t);
        $this->assertStringContainsString('GITA', $t);
        $this->assertStringContainsString('Di tempat - Meja 5', $t);
        $this->assertStringContainsString('2x Kopi Susu Bangku', $t);
        // Bawaan tidak ditulis kecuali "selalu cetak" (Es); pilihan lain & catatan ditulis (boleh terbungkus).
        $this->assertStringContainsString('Large, Es, Kurang manis, "es sedikit"', preg_replace('/\s+/', ' ', $t));
        $this->assertStringNotContainsString('Tanpa', $t);
        $this->assertStringContainsString('3 item', $t);
        $this->assertStringContainsString('B-1019-001', $t);

        $r = $this->text($receipt);
        foreach (['Bangku Coffee', 'No. B-1019-001', 'Kasir: Rani', 'Antrean #1 - Gita', '2 x 27.000', '54.000', 'Croissant Butter', 'Rp 74.000', 'Kembali', '26.000', 'Terima kasih'] as $needle) {
            $this->assertStringContainsString($needle, $r);
        }

        foreach (array_merge($ticket['lines'], $receipt['lines']) as $line) {
            $this->assertLessThanOrEqual(32, strlen($line['text']), $line['text']);
        }

        $this->assertStringStartsWith("\x1b@", base64_decode($job['escpos']));
    }

    public function test_struk_bisa_dimatikan_dan_tiket_dari_pengaturan(): void
    {
        $job = $this->job($this->payKopi($this->kasir(), receipt: false), 'paid');
        $this->assertCount(1, $job['docs']);
        $this->assertStringContainsString('TIKET BARISTA', $this->text($job['docs'][0]));

        $this->setting('printing.ticket', false);
        $kasir = $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Hana')->call('pay', PaymentMethod::firstWhere('name', 'QRIS')->id);
        $job = $this->job($kasir, 'paid');
        $this->assertCount(1, $job['docs']);
        $this->assertStringContainsString('QRIS', $this->text($job['docs'][0]));
        $this->assertStringNotContainsString('Kembali', $this->text($job['docs'][0]));
    }

    public function test_simpan_mencetak_tiket_dan_tambahan_hanya_item_baru(): void
    {
        $kasir = $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Ika')->call('hold');
        $first = $this->text($this->job($kasir, 'print')['docs'][0]);
        $this->assertStringContainsString('TIKET BARISTA', $first);
        $this->assertStringContainsString('1x Americano', $first);

        $kasir = $this->kasir()->call('openHeld', Order::sole()->id)->call('addLine', $this->id('Air Mineral'), [], 2)->call('hold');
        $extra = $this->text($this->job($kasir, 'print')['docs'][0]);
        $this->assertStringContainsString('TIKET BARISTA - TAMBAHAN', $extra);
        $this->assertStringContainsString('2x Air Mineral', $extra);
        $this->assertStringNotContainsString('Americano', $extra);

        // Dibayar tanpa tambahan: tidak ada tiket, hanya struk.
        $kasir = $this->kasir()->call('openHeld', Order::sole()->id)->call('pay', PaymentMethod::firstWhere('name', 'QRIS')->id);
        $job = $this->job($kasir, 'paid');
        $this->assertCount(1, $job['docs']);
        $this->assertStringContainsString('Air Mineral', $this->text($job['docs'][0]));
    }

    public function test_cetak_ulang_dan_tes_printer(): void
    {
        $this->payKopi($this->kasir());
        $order = Order::sole();

        $job = $this->job($this->kasir()->call('reprint', $order->id), 'print');
        $this->assertCount(2, $job['docs']);
        $this->assertStringContainsString('CETAK ULANG', $this->text($job['docs'][0]));
        $this->assertStringContainsString('CETAK ULANG', $this->text($job['docs'][1]));

        $test = $this->text($this->job($this->kasir()->call('testPrint'), 'print')['docs'][0]);
        $this->assertStringContainsString('TES PRINTER', $test);
        $this->assertStringContainsString('12345678901234567890123456789012', $test);
    }

    public function test_struk_refund_ditandai(): void
    {
        $this->payKopi($this->kasir());
        $order = Order::sole();
        $this->kasir()->call('startRefund', $order->id)->call('confirmCorrection', Reason::ofType(ReasonType::Refund)->value('id'), '', '9999');

        $this->assertStringContainsString('DIREFUND', $this->text(app(Documents::class)->receipt($order->fresh())));
    }

    public function test_lebar_kolom_dari_pengaturan(): void
    {
        $this->setting('printing.cols', 48);
        $this->payKopi($this->kasir());

        $receipt = app(Documents::class)->receipt(Order::sole());
        $this->assertSame(48, $receipt['cols']);
        $this->assertSame(48, strlen(collect($receipt['lines'])->firstWhere(fn ($l) => str_starts_with($l['text'], 'Subtotal'))['text']));
    }

    public function test_laporan_shift_saat_tutup(): void
    {
        $this->payKopi($this->kasir());
        PaymentMethod::where('name', 'Kartu kredit')->update(['is_active' => true]);
        $bca = Bank::firstWhere('name', 'BCA');
        $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Joko')->call('pay', PaymentMethod::firstWhere('name', 'Kartu kredit')->id, $bca->id);
        $this->kasir()->call('addCash', 'out', 150000, Reason::ofType(ReasonType::CashOut)->firstWhere('label', 'Galon')->id, '', '3333');
        $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', 'Kiki')->call('hold');

        $kasir = $this->kasir()->call('closeShift', ['counts' => [100000 => 2], 'coins' => 0, 'float_left' => 100000, 'variance_note' => 'uji', 'day_close' => true, 'edc' => ['QRIS' => 0, 'Kartu kredit BCA' => 20000]], '3333')
            ->assertDispatched('shift-closed');
        $report = $this->text($this->job($kasir, 'shift-closed')['docs'][0]);

        foreach (['LAPORAN SHIFT & TUTUP HARI', 'Shift Pagi - Rani', 'PENJUALAN', 'Tunai', 'Kartu kredit BCA', 'TOTAL', '94.000', 'Modal awal', 'Keluar: Galon', 'disetujui Sinta', 'Seharusnya', '224.000', 'Dihitung', '200.000', 'Selisih', '-24.000', 'DISERAHKAN KE OWNER', '100.000', 'OPEN BILL DIALIHKAN', 'Kiki', 'SETTLEMENT EDC', '(Rani)'] as $needle) {
            $this->assertStringContainsString($needle, $report);
        }
    }

    public function test_riwayat_menampilkan_label_varian_yang_sama_dengan_tiket(): void
    {
        $this->payKopi($this->kasir());

        $this->kasir()->call('selectHistory', Order::sole()->id)->assertSee('Large · Es · Kurang manis · &quot;es sedikit&quot;', escape: false);
    }

    public function test_cetak_ulang_hanya_transaksi_di_riwayat_hari_ini(): void
    {
        $this->kasir()->call('addLine', MenuItem::firstWhere('name', 'Air Mineral')->id)->set('customerName', 'Lama')
            ->call('pay', PaymentMethod::firstWhere('name', 'QRIS')->id);
        $old = Order::latest('id')->first();
        $this->travel(2)->days();

        $this->kasir()->call('reprint', $old->id)->assertNotDispatched('print');
    }
}
