<?php

namespace Database\Seeders;

use App\Support\Settings;
use Illuminate\Database\Seeder;

/** Isi tabel settings dengan bawaan config/pos.php. Tidak menimpa nilai yang sudah diubah di admin. */
class SettingsSeeder extends Seeder
{
    public function run(Settings $settings): void
    {
        $settings->seedDefaults();
    }
}
