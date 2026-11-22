<?php

namespace App\Filament\Clusters\Settings;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Pengaturan toko: tab-tab seperti admin mockup (toko, pajak, jenis pesanan, pembayaran, shift, aturan, pelanggan, perangkat). */
class SettingsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'pengaturan';

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.cluster');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('admin.groups.system');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('admin.settings.cluster');
    }
}
