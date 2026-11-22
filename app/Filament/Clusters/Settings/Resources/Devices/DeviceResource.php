<?php

namespace App\Filament\Clusters\Settings\Resources\Devices;

use App\Filament\Clusters\Settings\Resources\Devices\Pages\ManageDevices;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Models\Device;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Tablet kasir terdaftar: cabut (token tidak berlaku lagi) & kode pendaftaran sekali pakai. */
class DeviceResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = Device::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string $langKey = 'devices';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'perangkat';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDeviceTablet;

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.devices.{$key}");

        return $table
            ->description($t('description'))
            ->defaultSort('registered_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable()->weight('medium'),
                TextColumn::make('registered_at')->label($t('registered_at'))->dateTime('j M Y H:i'),
                TextColumn::make('last_seen_at')->label($t('last_seen_at'))->since()->placeholder('–'),
                TextColumn::make('status')
                    ->label(__('admin.common.status'))
                    ->badge()
                    ->getStateUsing(fn (Device $record) => $record->isRevoked() ? $t('revoked') : $t('active'))
                    ->color(fn (Device $record) => $record->isRevoked() ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label($t('revoke'))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->requiresConfirmation()
                    ->modalDescription($t('revoke_confirm'))
                    ->visible(fn (Device $record) => ! $record->isRevoked())
                    ->authorize(fn (Device $record) => auth()->user()?->can('update', $record))
                    ->action(function (Device $record) use ($t) {
                        $record->revoke(auth()->user());
                        Notification::make()->success()->title($t('revoked_notice'))->send();
                    }),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDevices::route('/'),
        ];
    }
}
