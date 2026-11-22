<?php

namespace Tests\Feature\Pos;

use App\Livewire\Pos\Kasir;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\OrderSequence;
use App\Models\OrderType;
use App\Models\ShiftTemplate;
use App\Support\PosSession;
use App\Support\ShiftService;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

class KasirOrderTest extends PosTestCase
{
    private Device $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->device, $this->token] = $this->tablet();
        app(ShiftService::class)->open($this->user('Rani'), $this->device, ShiftTemplate::first()->id, [], 0, null);
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

    public function test_menu_tampil_dengan_habis_dan_terlaris(): void
    {
        $this->kasir()
            ->assertSee(['Kopi Susu Bangku', 'Croissant Butter', __('pos.order.sold_out'), __('pos.order.best')])
            ->assertSee('#1')
            ->assertSee('B-'.now()->format('md').'-001');
    }

    public function test_nomor_antrean_berikutnya_ditampilkan_tanpa_dipakai(): void
    {
        OrderSequence::create(['business_date' => CarbonImmutable::now(), 'last_number' => 6]);

        $this->kasir()->assertSee('#7')->assertSee('B-'.now()->format('md').'-007');
        $this->assertSame(6, OrderSequence::first()->last_number);
    }

    public function test_tambah_ubah_jumlah_dan_total_dari_server(): void
    {
        $size = ModifierGroup::firstWhere('name', 'Ukuran');
        $large = $size->options()->where('name', 'Large')->value('id');

        $kasir = $this->kasir()
            ->call('addLine', $this->id('Kopi Susu Bangku'), [$size->id => $large], 2, '')
            ->assertDispatched('toast')
            ->call('addLine', $this->id('Croissant Butter'))
            ->assertSee('Rp 74.000');

        $kasir->call('changeQty', 1, 1)->assertSee('Rp 94.000');
        $kasir->call('changeQty', 1, -1)->call('changeQty', 1, -1);
        $this->assertCount(1, $kasir->get('order')['lines']);

        $kasir->call('updateLine', 0, [], 1, 'es sedikit')->assertSee('"es sedikit"')->assertSee('Rp 22.000');
    }

    public function test_status_pesanan_tidak_bisa_diubah_langsung_dari_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->kasir()->set('order.lines', [['item_id' => $this->id('Americano'), 'picks' => [], 'qty' => 1, 'note' => '']]);
    }

    public function test_menu_habis_tidak_bisa_ditambahkan(): void
    {
        $this->kasir()->call('addLine', $this->id('Nasi Goreng Bangku'))->assertHasErrors(['item']);
    }

    public function test_jenis_pesanan_dan_meja(): void
    {
        $dine = OrderType::firstWhere('name', 'Di tempat');
        $take = OrderType::firstWhere('name', 'Bawa pulang');
        $online = OrderType::firstWhere('name', 'Ojek online'); // nonaktif

        $kasir = $this->kasir()->call('setType', $dine->id)->call('setTable', '012');
        $this->assertSame('12', $kasir->get('order')['table']);

        $kasir->call('setTable', '12a')->assertHasErrors(['table']);
        $kasir->call('setTable', '1234')->assertHasErrors(['table']);

        $kasir->call('setType', $take->id);
        $this->assertSame('', $kasir->get('order')['table']);
        $kasir->call('setTable', '5')->assertHasErrors(['table']);

        $kasir->call('setType', $online->id);
        $this->assertSame($take->id, $kasir->get('order')['type_id']);
    }

    public function test_diskon_di_bawah_batas_langsung_diterapkan(): void
    {
        $kasir = $this->kasir()->call('addLine', $this->id('Cappuccino'), [], 4)->call('applyDiscount', 'percent', 10)->assertNotDispatched('approval-required');

        $this->assertSame(['type' => 'percent', 'value' => 10, 'approved_by' => null], $kasir->get('order')['discount']);
        $kasir->assertSee('Rp 90.000');
    }

    public function test_diskon_di_atas_batas_perlu_pin_penyetuju(): void
    {
        $kasir = $this->kasir()->call('addLine', $this->id('Cappuccino'), [], 4)->call('applyDiscount', 'amount', 30000)->assertDispatched('approval-required');
        $this->assertNull($kasir->get('order')['discount']['type']);

        // PIN kasir biasa (tanpa hak menyetujui) ditolak.
        $kasir->call('approve', '1111')->assertDispatched('approval-failed');
        $this->assertNull($kasir->get('order')['discount']['type']);

        $kasir->call('approve', '3333')->assertDispatched('approval-done');
        $sinta = $this->user('Sinta');
        $this->assertSame(['type' => 'amount', 'value' => 30000, 'approved_by' => $sinta->id, 'approved_subtotal' => 100000], $kasir->get('order')['discount']);
        $kasir->assertSee('Rp 70.000');

        $log = AuditLog::firstWhere('event', 'approval.discount');
        $this->assertSame($sinta->id, $log->approved_by);
        $this->assertSame($this->user('Rani')->id, $log->user_id);
        $this->assertSame($this->device->id, $log->device_id);
    }

    public function test_pin_persetujuan_salah_berulang_dikunci(): void
    {
        $this->freezeTime(); // sisa detik kunci dihitung dari jam: jangan sampai berganti detik di tengah tes
        $kasir = $this->kasir()->call('addLine', $this->id('Cappuccino'), [], 4)->call('applyDiscount', 'percent', 50);

        foreach (range(1, 5) as $i) {
            $kasir->call('approve', '0000');
        }

        $kasir->call('approve', '3333')->assertDispatched('approval-failed', message: __('pos.login.errors.locked', ['seconds' => 60]));
        $this->assertNull($kasir->get('order')['discount']['type']);
        $this->assertTrue(AuditLog::where('event', 'auth.approval_locked')->exists());
    }

    public function test_batal_persetujuan_tidak_menerapkan_diskon(): void
    {
        $kasir = $this->kasir()->call('addLine', $this->id('Cappuccino'), [], 4)->call('applyDiscount', 'percent', 50)->call('cancelApproval')->call('approve', '3333');

        $this->assertNull($kasir->get('order')['discount']['type']);
    }

    public function test_diskon_tidak_valid_ditolak(): void
    {
        $kasir = $this->kasir()->call('addLine', $this->id('Cappuccino'));

        $kasir->call('applyDiscount', 'percent', 150)->assertHasErrors(['discount']);
        $kasir->call('applyDiscount', 'gratis', 10)->assertHasErrors(['discount']);
        $kasir->call('applyDiscount', 'amount', -5)->assertHasErrors(['discount']);
        $kasir->call('applyDiscount', null, 0)->assertHasNoErrors();
    }

    public function test_draf_tetap_ada_setelah_ganti_kasir_dan_bisa_dikosongkan(): void
    {
        $this->kasir()->call('addLine', $this->id('Americano'))->set('customerName', '  Ana  ');
        app(PosSession::class)->end();

        $kasir = $this->kasir('Dimas')->assertSee('Americano')->assertSet('customerName', 'Ana');

        $kasir->call('clearOrder')->assertSet('customerName', '');
        $this->assertSame([], $kasir->get('order')['lines']);
        $this->kasir('Dimas')->assertDontSee('data-dec="0"', escape: false)->assertSee('Belum ada item.');
    }

    public function test_nama_pelanggan_dibatasi_dan_di_escape(): void
    {
        $kasir = $this->kasir()->set('customerName', str_repeat('A', 40));
        $this->assertSame(Kasir::NAME_MAX, mb_strlen($kasir->get('customerName')));

        MenuItem::firstWhere('name', 'Americano')->update(['name' => '<b>Ame</b>']);
        $this->kasir()->call('addLine', $this->id('<b>Ame</b>'))->assertDontSee('<b>Ame</b>', escape: false)->assertSee('&lt;b&gt;Ame&lt;/b&gt;', escape: false);
    }

    public function test_pajak_dan_service_tampil_sesuai_pengaturan(): void
    {
        $this->setting('charges.service_enabled', true);
        $this->setting('charges.tax_enabled', true);

        $this->kasir()->call('addLine', $this->id('Cappuccino'), [], 4)
            ->assertSee('Service 5%')
            ->assertSee('PB1 10%')
            ->assertSee('Rp 115.500');
    }
}
