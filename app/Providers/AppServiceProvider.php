<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Http\Middleware\EnsurePosCashier;
use App\Http\Middleware\EnsurePosDevice;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\DiscountPreset;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Station;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Policies\MenuPolicy;
use App\Policies\OrderPolicy;
use App\Policies\RolePolicy;
use App\Policies\SettingsPolicy;
use App\Policies\ShiftPolicy;
use App\Policies\UserPolicy;
use App\Support\Settings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Aksi Livewire di layar kasir melewati middleware yang sama dengan halamannya
        // (tablet terdaftar, kasir masuk, kunci otomatis) — bukan hanya saat halaman dibuka.
        Livewire::addPersistentMiddleware([EnsurePosDevice::class, EnsurePosCashier::class]);

        // Halaman Tampilan (package tema) ikut grup "Sistem" bersama Pengaturan; teks dari terjemahan.
        config(['filament-shadcn.appearance_page.navigation_group' => __('admin.groups.system')]);

        // Setiap hak akses jadi Gate (Gate::allows('approve'), $user->can('menu'), Policy).
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }

        // Policy per model → hak yang dibutuhkan (dipakai Filament untuk semua aksi).
        foreach ([MenuItem::class, Category::class, ModifierGroup::class, Station::class] as $model) {
            Gate::policy($model, MenuPolicy::class);
        }

        foreach ([OrderType::class, PaymentMethod::class, Bank::class, DiscountPreset::class, Reason::class, Denomination::class, ShiftTemplate::class, Device::class] as $model) {
            Gate::policy($model, SettingsPolicy::class);
        }

        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Shift::class, ShiftPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // Aturan kata sandi untuk form profil, daftar, dan reset sandi (config/security.php).
        Password::defaults(function (): Password {
            $config = config('security.password');
            $rule = Password::min((int) ($config['min'] ?? 12));

            if ($config['letters'] ?? true) {
                $rule->letters();
            }

            if ($config['numbers'] ?? true) {
                $rule->numbers();
            }

            if ($config['uncompromised'] ?? false) {
                $rule->uncompromised();
            }

            return $rule;
        });
    }
}
