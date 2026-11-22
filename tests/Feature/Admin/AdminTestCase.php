<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\SettingsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dasar tes panel admin: konfigurasi mockup + karyawan per peran. */
abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([SettingsSeeder::class, ConfigSeeder::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function owner(): User
    {
        return User::factory()->admin()->create(['role_id' => Role::firstWhere('name', 'Owner')->id]);
    }

    /** Peran dari mockup: Owner, Kepala toko, Kasir, Barista. */
    protected function staff(string $role, array $attributes = []): User
    {
        return User::factory()->create(['role_id' => Role::firstWhere('name', $role)->id] + $attributes);
    }
}
