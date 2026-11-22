<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_perangkat_disimpan_sebagai_hash_dan_bisa_dicari(): void
    {
        $owner = User::factory()->admin()->create();
        [$device, $token] = Device::register('Tablet Kasir 1', $owner);

        $this->assertNotSame($token, $device->token_hash);
        $this->assertSame(64, strlen($device->token_hash));
        $this->assertArrayNotHasKey('token_hash', $device->toArray());
        $this->assertTrue(Device::findByToken($token)->is($device));
        $this->assertNull(Device::findByToken('salah'));
        $this->assertNull(Device::findByToken(null));
        $this->assertSame($owner->id, $device->registered_by);
    }

    public function test_perangkat_yang_dicabut_tidak_dikenali(): void
    {
        [$device, $token] = Device::register('Tablet Kasir 1');

        $device->revoke(User::factory()->admin()->create());

        $this->assertTrue($device->fresh()->isRevoked());
        $this->assertNull(Device::findByToken($token));
    }

    public function test_token_tidak_bisa_diisi_massal(): void
    {
        $device = new Device(['name' => 'Tablet', 'token_hash' => 'abc', 'revoked_at' => null]);

        $this->assertNull($device->token_hash);
    }

    public function test_kode_pendaftaran_sekali_pakai_dan_kedaluwarsa(): void
    {
        [$row, $code] = DevicePairingCode::generate('Tablet Kasir 2', now()->addHour());
        [, $expired] = DevicePairingCode::generate('Tablet Lama', now()->subMinute(), code: '111111');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, $row->code_hash);
        $this->assertTrue(DevicePairingCode::findUsable($code)->is($row));
        $this->assertNull(DevicePairingCode::findUsable($expired));

        $row->forceFill(['used_at' => now()])->save();
        $this->assertNull(DevicePairingCode::findUsable($code));
    }
}
