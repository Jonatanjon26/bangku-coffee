<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\DevicePairingCode;
use Illuminate\Database\Seeder;

/** Tablet & kode pendaftaran contoh dari mockup — hanya local/testing. */
class DeviceSeeder extends Seeder
{
    public const PAIRING_CODE = '246810';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        if (! Device::where('name', 'Tablet Kasir 1')->exists()) {
            Device::register('Tablet Kasir 1');
        }

        if (! DevicePairingCode::findUsable(self::PAIRING_CODE)) {
            DevicePairingCode::generate('Tablet Kasir 2', now()->addDays(30), code: self::PAIRING_CODE);
        }
    }
}
