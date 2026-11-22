<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Support\SalesReport;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/** Dasbor (mockup admin): ringkasan hari ini untuk yang punya hak laporan; lainnya hanya kartu akun. */
class Dashboard extends BaseDashboard
{
    public function getSubheading(): string|Htmlable|null
    {
        return __('admin.dashboard.subheading', ['date' => now()->translatedFormat('j F Y')]);
    }

    public function content(Schema $schema): Schema
    {
        $canReport = (bool) auth()->user()?->hasPermission(Permission::Reports);

        return $schema->components([
            View::make('filament.pages.dashboard')->viewData(fn () => [
                'canReport' => $canReport,
                'd' => $canReport ? app(SalesReport::class)->dashboard() : null,
            ]),
        ]);
    }
}
