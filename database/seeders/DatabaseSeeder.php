<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     * Data transaksi contoh 14 hari terpisah: php artisan db:seed --class=DemoSeeder (hanya local).
     */
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            ConfigSeeder::class,
            AdminSeeder::class,
            StaffSeeder::class,
            DeviceSeeder::class,
        ]);
    }
}
