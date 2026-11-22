<?php

namespace App\Filament\Clusters\Settings\Resources\Devices\Pages;

use App\Filament\Clusters\Settings\Resources\Devices\DeviceResource;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

class ManageDevices extends ManageRecords
{
    protected static string $resource = DeviceResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $pending = DevicePairingCode::whereNull('used_at')->where('expires_at', '>', now())->pluck('device_name');

        return $pending->isEmpty() ? null : __('admin.devices.pending_codes', ['names' => $pending->implode(', ')]);
    }

    protected function getHeaderActions(): array
    {
        $t = fn (string $key, array $replace = []) => __("admin.devices.{$key}", $replace);
        $canManage = fn () => (bool) auth()->user()?->can('create', Device::class);

        return [
            Action::make('pair')
                ->label($t('pair'))
                ->icon(Heroicon::OutlinedQrCode)
                ->authorize($canManage)
                ->schema([
                    TextInput::make('device_name')
                        ->label($t('pair_name'))
                        ->default(fn () => 'Tablet Kasir '.(Device::count() + 1))
                        ->required()
                        ->maxLength(60),
                ])
                ->action(function (array $data) use ($t) {
                    $hours = (int) app(Settings::class)->get('devices.pairing_code_hours');

                    // Satu kode aktif: kode lama yang belum dipakai dibatalkan.
                    [$row, $code] = DB::transaction(function () use ($data, $hours) {
                        DevicePairingCode::whereNull('used_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);

                        return DevicePairingCode::generate($data['device_name'], now()->addHours($hours), auth()->user());
                    });

                    Notification::make()
                        ->success()
                        ->persistent()
                        ->title($t('pair_created', ['name' => $row->device_name]))
                        ->body($t('pair_created_body', ['code' => $code, 'until' => $row->expires_at->translatedFormat('j M H:i')]))
                        ->send();
                }),
            Action::make('cancelCodes')
                ->label($t('cancel_codes'))
                ->color('gray')
                ->authorize($canManage)
                ->requiresConfirmation()
                ->modalDescription($t('cancel_codes_confirm'))
                ->action(function () use ($t) {
                    $count = DevicePairingCode::whereNull('used_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
                    Notification::make()->success()->title($t('codes_cancelled', ['count' => $count]))->send();
                }),
        ];
    }
}
