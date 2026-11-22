<?php

namespace Tests\Feature\Pos;

use App\Livewire\Pos\Login;
use App\Models\Device;
use App\Models\DevicePairingCode;
use Database\Seeders\DeviceSeeder;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;

class DevicePairingTest extends PosTestCase
{
    public function test_tablet_baru_melihat_langkah_daftar_tanpa_nama_karyawan(): void
    {
        $this->get('/')->assertRedirect('/masuk');

        $this->get('/masuk')
            ->assertOk()
            ->assertSee(__('pos.login.pair_title'))
            ->assertDontSee('Rani');
    }

    public function test_kode_benar_mendaftarkan_tablet_dan_langsung_menampilkan_kasir(): void
    {
        $component = Livewire::test(Login::class)
            ->assertSet('step', 'pair')
            ->call('pair', DeviceSeeder::PAIRING_CODE)
            ->assertSet('step', 'people')
            ->assertDispatched('toast')
            // Regresi: daftar kasir harus tampil di respons yang sama (cookie baru belum ada di permintaan).
            ->assertSee('Rani')
            ->assertSee('Dimas');

        $device = Device::firstWhere('name', 'Tablet Kasir 2');
        $this->assertNotNull($device);
        $this->assertTrue(Cookie::hasQueued($this->cookieName()));
        $token = Cookie::queued($this->cookieName())->getValue();
        $this->assertTrue(Device::findByToken($token)->is($device));
        $this->assertNotSame($token, $device->token_hash);
        $this->assertNotNull(DevicePairingCode::firstWhere('device_id', $device->id)->used_at);
        $component->assertHasNoErrors();
    }

    public function test_kode_sekali_pakai(): void
    {
        Livewire::test(Login::class)->call('pair', DeviceSeeder::PAIRING_CODE)->assertSet('step', 'people');

        Livewire::test(Login::class)
            ->call('pair', DeviceSeeder::PAIRING_CODE)
            ->assertSet('step', 'pair')
            ->assertSet('error', __('pos.login.errors.invalid_code'))
            ->assertDispatched('keypad-failed');

        $this->assertSame(1, Device::where('name', 'Tablet Kasir 2')->count());
    }

    public function test_kode_kedaluwarsa_ditolak(): void
    {
        DevicePairingCode::generate('Tablet Lama', now()->subMinute(), code: '135790');

        Livewire::test(Login::class)->call('pair', '135790')->assertSet('step', 'pair');
        $this->assertFalse(Device::where('name', 'Tablet Lama')->exists());
    }

    public function test_kode_salah_berulang_dikunci_sementara(): void
    {
        $this->freezeTime(); // sisa detik kunci dihitung dari jam
        $this->setting('policy.max_pin_attempts', 3);
        $this->setting('policy.lock_seconds', 60);

        foreach (['000001', '000002'] as $wrong) {
            Livewire::test(Login::class)->call('pair', $wrong)->assertSet('error', __('pos.login.errors.invalid_code'));
        }

        Livewire::test(Login::class)->call('pair', '000003')->assertSet('error', __('pos.login.errors.locked', ['seconds' => 60]));

        // Selama terkunci, kode benar pun ditolak.
        Livewire::test(Login::class)->call('pair', DeviceSeeder::PAIRING_CODE)->assertSet('step', 'pair');
        $this->assertFalse(Device::where('name', 'Tablet Kasir 2')->exists());

        $this->travel(61)->seconds();
        Livewire::test(Login::class)->call('pair', DeviceSeeder::PAIRING_CODE)->assertSet('step', 'people');
    }

    public function test_tablet_yang_dicabut_harus_didaftarkan_ulang(): void
    {
        [$device, $token] = $this->tablet();
        $device->revoke();

        $this->withCookie($this->cookieName(), $token)->get('/masuk')->assertSee(__('pos.login.pair_title'))->assertDontSee('Rani');
        $this->withCookie($this->cookieName(), $token)->get('/kasir')->assertRedirect(route('pos.login'));
    }

    public function test_cookie_palsu_tidak_dikenali(): void
    {
        $this->withCookie($this->cookieName(), 'token-karangan')->get('/masuk')->assertSee(__('pos.login.pair_title'));
    }
}
