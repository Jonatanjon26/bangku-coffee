<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Mehuli\FilamentShadcn\Pages\Auth\EditProfile;
use Mehuli\FilamentShadcn\Pages\Auth\Login;
use Mehuli\FilamentShadcn\ShadcnPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path(config('admin.panel_path'))
            ->brandName(fn (): string => config('app.name'))
            ->favicon(asset('favicon.svg'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('partials.favicon', ['svgLink' => false]))
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Warna, font, gaya: diatur plugin (config/filament-shadcn.php, .env SHADCN_*, atau halaman Tampilan).
            ->plugin(
                ShadcnPlugin::make()
                    ->canCustomizeAppearance(fn (): bool => (bool) auth()->user()?->is_admin),
            )
            ->login(Login::class)
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)
            ->databaseNotifications()
            ->darkMode(true)
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->navigationGroups([
                __('admin.groups.sales'),
                __('admin.groups.catalog'),
                __('admin.groups.relations'),
                __('admin.groups.system'),
            ])
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
