<?php

namespace Tests\Feature\Pos;

use App\Livewire\Pos\Login;
use Livewire\Livewire;

class KasirAccessTest extends PosTestCase
{
    public function test_kasir_butuh_tablet_terdaftar(): void
    {
        $this->actingAs($this->user('Rani'), 'pos')->get('/kasir')->assertRedirect(route('pos.login'));
    }

    public function test_kasir_butuh_pin_di_tablet(): void
    {
        [, $token] = $this->tablet();

        $this->withCookie($this->cookieName(), $token)->get('/kasir')->assertRedirect(route('pos.login'));
    }

    public function test_login_panel_admin_tidak_membuka_kasir_dan_sebaliknya(): void
    {
        [$device, $token] = $this->tablet();
        $owner = $this->user('Owner');

        $this->actingAs($owner, 'web')->withCookie($this->cookieName(), $token)->get('/kasir')->assertRedirect(route('pos.login'));

        auth('web')->logout();
        $this->signIn($owner, $device, $token)->get(route('filament.admin.pages.dashboard'))->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_kasir_masuk_melihat_namanya_dan_tablet_dicatat_terakhir_dipakai(): void
    {
        [$device, $token] = $this->tablet();

        $this->signIn($this->user('Rani'), $device, $token)->get('/kasir')->assertOk()->assertSee(__('pos.kasir.app', ['name' => 'Rani']));

        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_sesi_terikat_ke_tablet(): void
    {
        [$device] = $this->tablet();
        [, $otherToken] = $this->tablet('Tablet Lain');

        $this->actingAs($this->user('Rani'), 'pos')
            ->withSession(['pos.device_id' => $device->id, 'pos.last_activity' => now()->getTimestamp()])
            ->withCookie($this->cookieName(), $otherToken)
            ->get('/kasir')
            ->assertRedirect(route('pos.login'));
    }

    public function test_tablet_dicabut_saat_kasir_masuk(): void
    {
        [$device, $token] = $this->tablet();
        $device->revoke();

        $this->signIn($this->user('Rani'), $device, $token)->get('/kasir')->assertRedirect(route('pos.login'));
    }

    public function test_karyawan_dinonaktifkan_langsung_keluar(): void
    {
        [$device, $token] = $this->tablet();
        $rani = $this->user('Rani');
        $rani->update(['is_active' => false]);

        $this->signIn($rani, $device, $token)->get('/kasir')->assertRedirect(route('pos.login'));
    }

    public function test_kunci_otomatis_dicek_di_server(): void
    {
        $this->setting('policy.auto_lock_minutes', 5);
        [$device, $token] = $this->tablet();
        $this->signIn($this->user('Rani'), $device, $token)->get('/kasir')->assertOk();

        $this->travel(6)->minutes();
        $this->get('/kasir')->assertRedirect(route('pos.login', ['locked' => 1]));
        $this->assertNull(auth('pos')->user());
    }

    public function test_kunci_otomatis_mati_bila_nol(): void
    {
        $this->setting('policy.auto_lock_minutes', 0);
        [$device, $token] = $this->tablet();
        $this->signIn($this->user('Rani'), $device, $token)->get('/kasir')->assertOk();

        $this->travel(3)->hours();
        $this->get('/kasir')->assertOk();
    }

    public function test_tombol_kunci_mengakhiri_sesi_kasir(): void
    {
        [$device, $token] = $this->tablet();

        $this->signIn($this->user('Rani'), $device, $token)->post('/kasir/kunci')->assertRedirect(route('pos.login', ['locked' => 1]));
        $this->assertNull(auth('pos')->user());
        $this->get('/kasir')->assertRedirect(route('pos.login'));
    }

    public function test_sudah_masuk_langsung_ke_kasir_kecuali_baru_dikunci(): void
    {
        [$device, $token] = $this->tablet();
        $this->signIn($this->user('Rani'), $device, $token);

        Livewire::withCookies([$this->cookieName() => $token])->test(Login::class)->assertRedirect(route('pos.kasir'));
        Livewire::withCookies([$this->cookieName() => $token])->withQueryParams(['locked' => 1])->test(Login::class)
            ->assertNoRedirect()
            ->assertSee(__('pos.login.locked_notice'));
    }
}
