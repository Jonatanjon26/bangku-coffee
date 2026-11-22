<?php

namespace Tests\Feature\Pos;

use App\Enums\CashCountStage;
use App\Livewire\Pos\Kasir;
use App\Models\AuditLog;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\PosSession;
use App\Support\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class OpenShiftTest extends PosTestCase
{
    private Device $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->device, $this->token] = $this->tablet();
    }

    private function kasir(string $name = 'Rani')
    {
        $this->signIn($this->user($name), $this->device, $this->token);

        return Livewire::withCookies([$this->cookieName() => $this->token])->test(Kasir::class);
    }

    private function template(string $name): int
    {
        return ShiftTemplate::firstWhere('name', $name)->id;
    }

    /** Shift yang sudah ditutup dengan modal ditinggal (sumber serah terima). */
    private function closedShift(int $floatLeft): Shift
    {
        $shift = app(ShiftService::class)->open($this->user('Dimas'), $this->device, $this->template('Pagi'), [100000 => 3], 0, null);
        $shift->forceFill(['closed_at' => now(), 'closed_by' => $this->user('Dimas')->id, 'float_left' => $floatLeft])->save();

        return $shift;
    }

    public function test_tanpa_shift_berjalan_dialog_buka_shift_wajib_tampil(): void
    {
        $this->signIn($this->user('Rani'), $this->device, $this->token)
            ->get('/kasir')
            ->assertOk()
            ->assertSee('id="open-dlg"', escape: false)
            ->assertSee(__('pos.shift.open_title'))
            ->assertSee(__('pos.kasir.shift_closed'));
    }

    public function test_buka_shift_menyimpan_hitungan_per_pecahan_dan_total_dihitung_server(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19 07:10'));

        $this->kasir()
            ->call('openShift', $this->template('Pagi'), ['100000' => 2, '50000' => 2, '1000' => 0], 3500, '')
            ->assertHasNoErrors()
            ->assertDispatched('toast')
            ->assertDontSee('id="open-dlg"', escape: false)
            ->assertSee(__('pos.kasir.shift_label', ['name' => 'Pagi']));

        $shift = Shift::firstOrFail();
        $this->assertSame(303500, $shift->opening_float);
        $this->assertSame('Pagi', $shift->name);
        $this->assertSame('2026-10-19', $shift->business_date->toDateString());
        $this->assertSame($this->device->id, $shift->device_id);
        $this->assertSame($this->user('Rani')->id, $shift->opened_by);
        $this->assertTrue($shift->isOpen());
        $this->assertEqualsCanonicalizing(
            [[100000, 2, 200000], [50000, 2, 100000], [0, 1, 3500]],
            $shift->cashCounts->map(fn ($c) => [$c->denomination, $c->quantity, $c->amount])->all(),
        );
        $this->assertTrue($shift->cashCounts->every(fn ($c) => $c->stage === CashCountStage::Opening));
    }

    public function test_dialog_tidak_muncul_lagi_saat_shift_berjalan_walau_ganti_kasir(): void
    {
        app(ShiftService::class)->open($this->user('Rani'), $this->device, $this->template('Pagi'), [], 0, null);

        $this->signIn($this->user('Dimas'), $this->device, $this->token)
            ->get('/kasir')
            ->assertOk()
            ->assertDontSee('id="open-dlg"', escape: false)
            ->assertSee(__('pos.kasir.shift_label', ['name' => 'Pagi']));
    }

    public function test_tidak_bisa_membuka_dua_shift_di_satu_tablet(): void
    {
        $this->kasir()->call('openShift', $this->template('Pagi'), [], 0, '')->assertHasNoErrors();

        $this->expectException(ValidationException::class);
        app(ShiftService::class)->open($this->user('Dimas'), $this->device, $this->template('Sore'), [], 0, null);
    }

    public function test_tablet_lain_bisa_membuka_shift_sendiri(): void
    {
        app(ShiftService::class)->open($this->user('Rani'), $this->device, $this->template('Pagi'), [], 0, null);
        [$other] = $this->tablet('Tablet Lain');

        $shift = app(ShiftService::class)->open($this->user('Dimas'), $other, $this->template('Pagi'), [], 0, null);

        $this->assertSame($other->id, $shift->device_id);
    }

    public function test_serah_terima_beda_wajib_catatan_dan_tercatat_di_audit(): void
    {
        $previous = $this->closedShift(300000);

        $this->kasir()
            ->assertSee(__('pos.shift.handover', ['name' => 'Pagi', 'cashier' => 'Dimas', 'amount' => 'Rp 300.000']))
            ->call('openShift', $this->template('Sore'), ['100000' => 2, '50000' => 1], 0, '')
            ->assertHasErrors(['note']);
        $this->assertSame(1, Shift::count());

        $this->kasir()->call('openShift', $this->template('Sore'), ['100000' => 2, '50000' => 1], 0, 'dipakai beli es batu')->assertHasNoErrors();

        $shift = Shift::latest('id')->first();
        $this->assertSame($previous->id, $shift->previous_shift_id);
        $this->assertSame(300000, $shift->handover_expected);
        $this->assertSame('dipakai beli es batu', $shift->handover_note);
        $log = AuditLog::firstWhere('event', 'shift.handover_difference');
        $this->assertEquals(['counted' => 250000, 'difference' => -50000, 'note' => 'dipakai beli es batu'], $log->new_values);
        $this->assertSame($this->user('Rani')->id, $log->user_id);
    }

    public function test_serah_terima_sama_tanpa_catatan(): void
    {
        $this->closedShift(300000);

        $this->kasir()->call('openShift', $this->template('Sore'), ['100000' => 3], 0, '')->assertHasNoErrors();

        $this->assertNull(Shift::latest('id')->first()->handover_note);
        $this->assertSame(0, AuditLog::where('event', 'shift.handover_difference')->count());
    }

    public function test_hitungan_tidak_valid_ditolak(): void
    {
        Denomination::firstWhere('value', 1000)->update(['is_active' => false]);
        $open = fn (array $counts, int $coins = 0) => $this->kasir()->call('openShift', $this->template('Pagi'), $counts, $coins, '');

        $open(['75000' => 1])->assertHasErrors(['counts']);   // bukan pecahan terdaftar
        $open(['1000' => 1])->assertHasErrors(['counts']);    // pecahan nonaktif
        $open(['5000' => -1])->assertHasErrors(['counts']);
        $open(['5000' => 1000])->assertHasErrors(['counts']);
        $open(['5000' => 1.5])->assertHasErrors(['counts']);
        $open([], -100)->assertHasErrors(['counts']);
        $open([], 10_000_000)->assertHasErrors(['counts']);

        $this->assertSame(0, Shift::count());
    }

    public function test_shift_nonaktif_ditolak(): void
    {
        ShiftTemplate::firstWhere('name', 'Sore')->update(['is_active' => false]);

        $this->kasir()->call('openShift', $this->template('Sore'), [], 0, '')->assertHasErrors(['template']);
    }

    public function test_saran_shift_menurut_jam_dan_yang_belum_dibuka_hari_ini(): void
    {
        $service = app(ShiftService::class);

        $this->assertSame('Pagi', $service->suggestTemplate($this->device, CarbonImmutable::parse('2026-10-19 06:30'))->name);
        $this->assertSame('Pagi', $service->suggestTemplate($this->device, CarbonImmutable::parse('2026-10-19 09:00'))->name);
        $this->assertSame('Sore', $service->suggestTemplate($this->device, CarbonImmutable::parse('2026-10-19 15:10'))->name);

        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00'));
        $this->closedShift(300000);
        $this->assertSame('Sore', $service->suggestTemplate($this->device, CarbonImmutable::parse('2026-10-19 10:00'))->name);
    }

    public function test_aksi_livewire_ditolak_tanpa_sesi_kasir(): void
    {
        $component = $this->kasir();
        app(PosSession::class)->end();

        $component->call('openShift', $this->template('Pagi'), [], 0, '');

        $this->assertSame(0, Shift::count());
    }

    public function test_karyawan_tanpa_hak_kasir_tidak_bisa_membuka_shift(): void
    {
        $barista = User::factory()->create(['role_id' => Role::firstWhere('name', 'Barista')->id]);

        $this->expectException(ValidationException::class);
        app(ShiftService::class)->open($barista, $this->device, $this->template('Pagi'), [], 0, null);
    }
}
