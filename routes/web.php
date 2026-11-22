<?php

use App\Http\Controllers\Admin\ShiftReportController;
use App\Http\Controllers\Pos\LockController;
use App\Http\Middleware\EnsurePosCashier;
use App\Http\Middleware\EnsurePosDevice;
use App\Livewire\Pos\Kasir;
use App\Livewire\Pos\Login;
use App\Support\Settings;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/masuk');

// Masuk kasir di tablet: daftar tablet → pilih kasir → PIN.
Route::get('/masuk', Login::class)->name('pos.login');

Route::middleware(EnsurePosDevice::class)->group(function () {
    Route::post('/kasir/kunci', LockController::class)->name('pos.lock');

    Route::middleware(EnsurePosCashier::class)->group(function () {
        Route::get('/kasir', Kasir::class)->name('pos.kasir');
    });
});

// Manifest aplikasi (ikon layar beranda tablet); nama toko dari pengaturan.
Route::get('/manifest.webmanifest', function (Settings $settings) {
    $name = (string) $settings->get('store.name');

    return response()->json([
        'name' => $name,
        'short_name' => $name,
        'start_url' => '/masuk',
        'theme_color' => '#6f4e37',
        'background_color' => '#ffffff',
        'icons' => [
            ['src' => asset('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => asset('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => asset('icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
})->name('manifest');

// Laporan shift untuk dicetak dari panel admin: sesi panel yang sama (AuthenticateSession: sandi diganti →
// sesi lain berakhir), akses panel (canAccessPanel) dan hak laporan dicek di controller.
Route::middleware(['auth:web', AuthenticateSession::class])->get('/'.config('admin.panel_path').'/shift/{shift}/laporan', ShiftReportController::class)->name('admin.shift-report');
