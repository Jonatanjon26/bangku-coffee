<?php

namespace Tests\Feature\Pos;

use App\Livewire\Pos\Kasir;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\ShiftTemplate;
use App\Support\PosLogin;
use App\Support\PosLoginException;
use App\Support\ShiftService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** Karyawan mengganti PIN sendiri di tablet (PIN lama wajib, PIN baru sepanjang pengaturan & unik, dibatasi). */
class PinChangeTest extends PosTestCase
{
    private Device $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->device, $this->token] = $this->tablet();
        app(ShiftService::class)->open($this->user('Rani'), $this->device, ShiftTemplate::first()->id, [100000 => 3], 0, null);
    }

    private function kasir(string $name = 'Rani')
    {
        $this->signIn($this->user($name), $this->device, $this->token);

        return Livewire::withCookies([$this->cookieName() => $this->token])->test(Kasir::class);
    }

    public function test_ganti_pin_sendiri(): void
    {
        $this->kasir()->call('changePin', '1111', '4826', '4826')->assertDispatched('pin-changed')
            ->assertDispatched('toast', message: __('pos.pin_change.done'));

        $rani = $this->user('Rani');
        $this->assertSame($rani->id, app(PosLogin::class)->attempt($this->device, $rani->id, '4826')->id);
        $this->assertTrue(AuditLog::where('event', 'user.pin_changed_self')->where('user_id', $rani->id)->where('device_id', $this->device->id)->exists());
        $this->assertStringNotContainsString('4826', json_encode(AuditLog::where('auditable_id', $rani->id)->get()->toArray()));
    }

    public function test_pin_lama_salah_berulang_dikunci(): void
    {
        $this->freezeTime();
        $kasir = $this->kasir();

        foreach (range(1, 4) as $i) {
            $kasir->call('changePin', '9999', '4826', '4826')->assertDispatched('pin-change-failed', restart: true);
        }
        $kasir->call('changePin', '0000', '4826', '4826')->assertDispatched('pin-change-failed', message: __('pos.login.errors.locked', ['seconds' => 60]));
        // Terkunci: PIN lama yang benar pun ditolak sementara.
        $kasir->call('changePin', '1111', '4826', '4826')->assertNotDispatched('pin-changed');

        $this->assertTrue($this->user('Rani')->checkPin('1111'));
        $this->assertTrue(AuditLog::where('event', 'auth.pin_change_locked')->exists());
    }

    public function test_pin_baru_divalidasi(): void
    {
        $kasir = $this->kasir();

        $kasir->call('changePin', '1111', '48261', '48261')->assertDispatched('pin-change-failed', message: __('pos.pin_change.errors.length', ['length' => 4]));
        $kasir->call('changePin', '1111', '4826', '4827')->assertDispatched('pin-change-failed', message: __('pos.pin_change.errors.confirm'));
        $kasir->call('changePin', '1111', '1111', '1111')->assertDispatched('pin-change-failed', message: __('pos.pin_change.errors.same'));
        $this->assertTrue($this->user('Rani')->checkPin('1111'));

        // Kesalahan pengetikan PIN baru tidak menghabiskan jatah percobaan.
        $kasir->call('changePin', '1111', '4826', '4826')->assertDispatched('pin-changed');
    }

    public function test_pin_terpakai_tidak_bisa_dipakai_menebak_pin_orang_lain(): void
    {
        $this->freezeTime();
        $this->setting('policy.max_pin_attempts', 3);
        $kasir = $this->kasir();

        // 2222 milik Dimas, 3333 milik Sinta: pesan umum, dan setiap kali dihitung sebagai percobaan.
        $kasir->call('changePin', '1111', '2222', '2222')->assertDispatched('pin-change-failed', message: __('pos.pin_change.errors.unavailable'), restart: false);
        $kasir->call('changePin', '1111', '3333', '3333')->assertDispatched('pin-change-failed', message: __('pos.pin_change.errors.unavailable'));
        // Percobaan ketiga mencapai batas → terkunci, tidak bisa terus menebak.
        $kasir->call('changePin', '1111', '9999', '9999')->assertDispatched('pin-change-failed', message: __('pos.login.errors.locked', ['seconds' => 60]));
        $kasir->call('changePin', '1111', '7777', '7777')->assertDispatched('pin-change-failed', message: __('pos.login.errors.locked', ['seconds' => 60]));
        $this->assertTrue($this->user('Rani')->checkPin('1111'));
    }

    public function test_menebak_pin_diselingi_ganti_pin_berhasil_tetap_dibatasi(): void
    {
        // Pola serangan dari tinjauan keamanan: tebak PIN orang lain, sesekali ganti ke PIN bebas supaya hitungan
        // salah tereset, lalu tebak lagi. Penghitung "PIN terpakai" per hari tidak boleh ikut tereset.
        $rani = $this->user('Rani');
        $current = '1111';
        $taken = [];
        $login = app(PosLogin::class);

        foreach (range(1000, 1300) as $i) {
            $candidate = (string) $i;

            try {
                $login->changePin($rani->fresh(), $this->device, $current, $candidate, $candidate);
                $current = $candidate; // PIN bebas → berhasil berganti
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->first();
                if ($message === __('pos.pin_change.errors.unavailable')) {
                    $taken[] = $candidate;
                }
                if ($message === __('pos.pin_change.errors.daily_limit')) {
                    break;
                }
            } catch (PosLoginException) {
                break;
            }
        }

        // Paling banyak 3 jawaban "terpakai" & 3 ganti PIN berhasil per hari — ruang PIN tidak bisa disapu.
        $this->assertLessThanOrEqual(3, count($taken));
        $this->assertLessThan(10, $i - 1000);
        $this->assertTrue(AuditLog::where('event', 'auth.pin_change_limit')->exists());

        // Besok jatahnya pulih.
        $this->travel(25)->hours();
        $login->changePin($rani->fresh(), $this->device, $current, '8642', '8642');
        $this->assertTrue($rani->fresh()->checkPin('8642'));
    }

    public function test_batas_ganti_pin_per_hari(): void
    {
        $login = app(PosLogin::class);
        $rani = $this->user('Rani');

        $login->changePin($rani->fresh(), $this->device, '1111', '4821', '4821');
        $login->changePin($rani->fresh(), $this->device, '4821', '4822', '4822');
        $login->changePin($rani->fresh(), $this->device, '4822', '4823', '4823');

        $this->expectExceptionMessage(__('pos.pin_change.errors.daily_limit'));
        $login->changePin($rani->fresh(), $this->device, '4823', '4824', '4824');
    }

    public function test_peralihan_4_ke_6_digit_dari_kasir(): void
    {
        $this->setting('policy.pin_length', 6);

        // PIN lama 4 digit masih dipakai masuk; tombol diberi tanda; PIN baru wajib 6 digit.
        $kasir = $this->kasir()->assertSeeHtml('id="pin-btn"')->assertSee(__('pos.pin_change.outdated', ['length' => 6]));
        $kasir->call('changePin', '1111', '4826', '4826')->assertDispatched('pin-change-failed', message: __('pos.pin_change.errors.length', ['length' => 6]));
        $kasir->call('changePin', '1111', '482615', '482615')->assertDispatched('pin-changed');

        $this->assertSame(6, $this->user('Rani')->pin_length);
        $this->kasir()->assertDontSee(__('pos.pin_change.outdated', ['length' => 6]));
    }

    public function test_tanpa_sesi_kasir_ditolak(): void
    {
        $this->expectException(PosLoginException::class);
        app(PosLogin::class)->changePin($this->user('Rani'), $this->device, '0000', '4826', '4826');
    }
}
