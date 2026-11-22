<?php

namespace Tests\Feature;

use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ikon aplikasi tersedia di halaman tablet & panel admin; manifest memakai nama toko dari pengaturan. */
class FaviconTest extends TestCase
{
    use RefreshDatabase;

    public function test_berkas_ikon_ada_dan_tidak_kosong(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png'] as $file) {
            $this->assertFileExists(public_path($file));
            $this->assertGreaterThan(100, filesize(public_path($file)), $file);
        }
    }

    public function test_halaman_tablet_dan_panel_memasang_ikon(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/masuk')->assertOk()->assertSee('favicon.svg', false)->assertSee('apple-touch-icon.png', false)->assertSee(route('manifest'), false);
        $this->get('/admin/login')->assertOk()->assertSee('favicon.svg', false)->assertSee(route('manifest'), false);
    }

    public function test_manifest_memakai_nama_toko(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(Settings::class)->set('store.name', 'Bangku Uji');

        $this->get(route('manifest'))->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Bangku Uji')
            ->assertJsonPath('start_url', '/masuk')
            ->assertJsonCount(3, 'icons');
    }
}
