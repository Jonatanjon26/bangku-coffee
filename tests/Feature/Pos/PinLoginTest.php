<?php

namespace Tests\Feature\Pos;

use App\Livewire\Pos\Login;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\Approval;
use App\Support\PosLogin;
use App\Support\PosLoginException;
use App\Support\PosSession;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

class PinLoginTest extends PosTestCase
{
    private function login(string $token)
    {
        return Livewire::withCookies([$this->cookieName() => $token])->test(Login::class);
    }

    public function test_daftar_kasir_hanya_karyawan_aktif_berhak_kasir(): void
    {
        [, $token] = $this->tablet();
        User::factory()->create(['name' => 'Tono Barista', 'role_id' => Role::firstWhere('name', 'Barista')->id]);
        User::factory()->inactive()->create(['name' => 'Lia Keluar', 'role_id' => Role::firstWhere('name', 'Kasir')->id]);

        $this->login($token)
            ->assertSet('step', 'people')
            ->assertSee(['Rani', 'Dimas', 'Sinta', 'Owner'])
            ->assertDontSee('Tono Barista')
            ->assertDontSee('Lia Keluar');
    }

    public function test_nama_karyawan_di_escape(): void
    {
        [, $token] = $this->tablet();
        $this->user('Rani')->update(['name' => '<img src=x onerror=alert(1)>']);

        $this->withCookie($this->cookieName(), $token)->get('/masuk')
            ->assertDontSee('<img src=x onerror=alert(1)>', escape: false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', escape: false);
    }

    public function test_pin_benar_masuk_ke_kasir_dengan_guard_terpisah(): void
    {
        [$device, $token] = $this->tablet();
        $rani = $this->user('Rani');

        $this->login($token)
            ->call('select', $rani->id)
            ->assertSet('step', 'pin')
            ->call('login', '1111')
            ->assertRedirect(route('pos.kasir'));

        $this->assertSame($rani->id, auth('pos')->id());
        $this->assertNull(auth('web')->user());
        $this->assertSame($device->id, session('pos.device_id'));
    }

    public function test_pin_salah_menampilkan_sisa_percobaan(): void
    {
        [, $token] = $this->tablet();

        $this->login($token)
            ->call('select', $this->user('Rani')->id)
            ->call('login', '2222')
            ->assertNoRedirect()
            ->assertSet('error', __('pos.login.errors.wrong_pin', ['remaining' => 4]))
            ->assertDispatched('keypad-failed');

        $this->assertNull(auth('pos')->user());
    }

    public function test_salah_pin_berulang_mengunci_karyawan_sementara(): void
    {
        [$device, $token] = $this->tablet();
        $rani = $this->user('Rani');
        $component = $this->login($token)->call('select', $rani->id);

        foreach (range(1, 4) as $i) {
            $component->call('login', '0000')->assertSet('lockedSeconds', 0);
        }

        $component->call('login', '0000')->assertSet('lockedSeconds', 60);
        $log = AuditLog::firstWhere('event', 'auth.pin_locked');
        $this->assertSame($rani->id, $log->user_id);
        $this->assertSame($device->id, $log->device_id);

        // PIN benar pun ditolak selama terkunci, juga setelah memilih ulang.
        $this->login($token)->call('select', $rani->id)->assertSet('lockedSeconds', 60)->call('login', '1111')->assertNoRedirect();
        $this->assertNull(auth('pos')->user());

        // Karyawan lain tetap bisa masuk.
        $this->login($token)->call('select', $this->user('Dimas')->id)->call('login', '2222')->assertRedirect(route('pos.kasir'));
        app(PosSession::class)->end(); // Dimas mengunci kasir

        $this->travel(61)->seconds();
        $this->login($token)->call('select', $rani->id)->assertSet('lockedSeconds', 0)->call('login', '1111')->assertRedirect(route('pos.kasir'));
    }

    public function test_batas_salah_pin_per_tablet_untuk_semua_karyawan(): void
    {
        $this->setting('policy.max_pin_attempts_device', 4);
        [, $token] = $this->tablet();

        foreach (['Rani', 'Dimas', 'Sinta'] as $name) {
            $this->login($token)->call('select', $this->user($name)->id)->call('login', '0000')->assertSet('lockedSeconds', 0);
        }

        $this->login($token)->call('select', $this->user('Owner')->id)->call('login', '0000')->assertSet('lockedSeconds', 60);

        // Tablet terkunci: siapa pun, PIN benar pun, ditolak.
        $this->login($token)->call('select', $this->user('Rani')->id)->call('login', '1111')->assertNoRedirect();
        $this->assertTrue(AuditLog::where('event', 'auth.pin_locked')->where('new_values->scope', 'device')->exists());

        // Tablet lain tidak ikut terkunci.
        [, $other] = $this->tablet('Tablet Lain');
        $this->login($other)->call('select', $this->user('Rani')->id)->call('login', '1111')->assertRedirect(route('pos.kasir'));
    }

    public function test_karyawan_tanpa_hak_kasir_ditolak_di_server(): void
    {
        [$device] = $this->tablet();
        $barista = User::factory()->create(['role_id' => Role::firstWhere('name', 'Barista')->id, 'pin' => '5555']);

        try {
            app(PosLogin::class)->attempt($device, $barista->id, '5555');
            $this->fail('Barista seharusnya ditolak');
        } catch (PosLoginException $e) {
            $this->assertSame(PosLoginException::NOT_ALLOWED, $e->reason);
        }
    }

    public function test_pilihan_karyawan_tidak_bisa_diubah_dari_browser(): void
    {
        [, $token] = $this->tablet();
        $barista = User::factory()->create(['role_id' => Role::firstWhere('name', 'Barista')->id, 'pin' => '5555']);

        $this->login($token)->call('select', $barista->id)->assertSet('step', 'people')->assertSet('userId', null);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->login($token)->set('userId', $barista->id);
    }

    public function test_aksi_tanpa_tablet_terdaftar_tidak_bisa_masuk(): void
    {
        Livewire::test(Login::class)->call('select', $this->user('Rani')->id)->assertSet('step', 'pair')->call('login', '1111')->assertNoRedirect();

        $this->assertNull(auth('pos')->user());
    }

    public function test_panjang_pin_mengikuti_pengaturan(): void
    {
        [$device] = $this->tablet();
        $rani = $this->user('Rani');
        $rani->update(['pin' => '123456']);
        $this->setting('policy.pin_length', 6);

        $this->assertSame($rani->id, app(PosLogin::class)->attempt($device, $rani->id, '123456')->id);
    }

    public function test_masuk_kasir_mengakhiri_sesi_admin_di_browser_tablet(): void
    {
        [, $token] = $this->tablet();
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->assertNotNull(auth('web')->user());

        $this->login($token)->call('select', $this->user('Rani')->id)->call('login', '1111')->assertRedirect(route('pos.kasir'));

        $this->assertNull(auth('web')->user());
    }

    public function test_pin_lama_tetap_bisa_masuk_selama_masa_peralihan(): void
    {
        [$device, $token] = $this->tablet();
        $rani = $this->user('Rani'); // PIN 1111 (4 digit)
        $this->setting('policy.pin_length', 6);

        // Keypad mengikuti panjang PIN orang yang dipilih; PIN lama masih diterima server.
        $this->login($token)->call('select', $rani->id)->assertSee(__('pos.login.pin_sub', ['role' => $rani->role->name, 'length' => 4]))
            ->call('login', '1111')->assertRedirect(route('pos.kasir'));

        // Setelah diganti, hanya PIN baru (6 digit) yang berlaku.
        $rani->update(['pin' => '135790']);
        $this->assertSame($rani->id, app(PosLogin::class)->attempt($device, $rani->id, '135790')->id);
        $this->expectException(PosLoginException::class);
        app(PosLogin::class)->attempt($device, $rani->id, '1111');
    }

    public function test_persetujuan_hanya_dengan_pin_sepanjang_pengaturan(): void
    {
        [$device] = $this->tablet();
        $this->setting('policy.pin_length', 6);

        // Sinta (penyetuju) masih PIN 3333: persetujuan di tablet menunggu PIN barunya.
        try {
            app(Approval::class)->verify($device, '3333', 'discount');
            $this->fail('PIN penyetuju lama tidak boleh dipakai setelah panjang PIN diubah');
        } catch (PosLoginException $e) {
            $this->assertSame(PosLoginException::WRONG_PIN, $e->reason);
        }

        $this->user('Sinta')->update(['pin' => '333333']);
        $this->assertSame('Sinta', app(Approval::class)->verify($device, '333333', 'discount')->name);
    }
}
