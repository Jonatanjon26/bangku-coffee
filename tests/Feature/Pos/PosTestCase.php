<?php

namespace Tests\Feature\Pos;

use App\Models\Device;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dasar tes tablet kasir: data mockup (Rani 1111, Dimas 2222, Sinta 3333, Owner 9999, kode 246810). */
abstract class PosTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Jam tetap di siang hari: tanggal bisnis (jam pergantian hari) tidak bergantung jam tes dijalankan.
        // Tes yang butuh jam tertentu memanggil travelTo sendiri.
        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));

        config(['admin.email' => 'owner@bangkucoffee.id', 'admin.password' => 'OwnerContoh2026']);
        $this->seed(DatabaseSeeder::class);
    }

    protected function cookieName(): string
    {
        return config('pos.device_cookie.name');
    }

    /** @return array{0: Device, 1: string} */
    protected function tablet(string $name = 'Tablet Uji'): array
    {
        return Device::register($name);
    }

    protected function user(string $name): User
    {
        return User::firstWhere('name', $name);
    }

    protected function setting(string $key, mixed $value): void
    {
        app(Settings::class)->set($key, $value);
    }

    /** Sesi kasir di tablet (seperti setelah PIN benar). */
    protected function signIn(User $user, Device $device, string $token): static
    {
        return $this->actingAs($user, 'pos')
            ->withSession(['pos.device_id' => $device->id, 'pos.last_activity' => now()->getTimestamp()])
            ->withCookie($this->cookieName(), $token);
    }
}
