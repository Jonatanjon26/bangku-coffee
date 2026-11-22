<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

class PolicySettings extends SettingsPage
{
    protected static string $section = 'policy';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'aturan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected function keys(): array
    {
        return [
            'policy.variance_approval_above', 'policy.cash_out_approval_above', 'policy.cash_out_shift_approval_above', 'discounts.approval_above_pct', 'policy.refund_needs_approval',
            'policy.carry_open_bills', 'policy.open_bill_day_approval', 'policy.open_bill_max_days', 'policy.require_customer_name', 'orders.day_change_hour',
            'policy.pin_length', 'policy.max_pin_attempts', 'policy.max_pin_attempts_device', 'policy.lock_seconds', 'policy.auto_lock_minutes', 'devices.pairing_code_hours',
        ];
    }

    protected function components(): array
    {
        return [
            Section::make($this->label('approvals'))->columns(2)->schema([
                TextInput::make('policy.variance_approval_above')->label($this->label('variance_approval_above'))->helperText($this->label('variance_approval_above_help'))->prefix('Rp')->integer()->required()->minValue(0),
                TextInput::make('policy.cash_out_approval_above')->label($this->label('cash_out_approval_above'))->prefix('Rp')->integer()->required()->minValue(0),
                TextInput::make('policy.cash_out_shift_approval_above')->label($this->label('cash_out_shift_approval_above'))->helperText($this->label('cash_out_shift_approval_above_help'))->prefix('Rp')->integer()->required()->minValue(0),
                TextInput::make('discounts.approval_above_pct')->label($this->label('approval_above_pct'))->suffix('%')->integer()->required()->minValue(0)->maxValue(100),
                Toggle::make('policy.refund_needs_approval')->label($this->label('refund_needs_approval'))->inline(false),
            ]),
            Section::make($this->label('open_bills'))->columns(2)->schema([
                Toggle::make('policy.carry_open_bills')->label($this->label('carry_open_bills'))->helperText($this->label('carry_open_bills_help')),
                Toggle::make('policy.open_bill_day_approval')->label($this->label('open_bill_day_approval')),
                TextInput::make('policy.open_bill_max_days')->label($this->label('open_bill_max_days'))->integer()->required()->minValue(1)->maxValue(30),
                Select::make('orders.day_change_hour')->label($this->label('day_change_hour'))->helperText($this->label('day_change_hour_help'))
                    ->options(collect(range(0, 6))->mapWithKeys(fn (int $h) => [$h => sprintf('%02d.00', $h)]))->required()->native(false),
                Toggle::make('policy.require_customer_name')->label($this->label('require_customer_name'))->helperText($this->label('require_customer_name_help')),
            ]),
            Section::make($this->label('security'))->columns(2)->schema([
                Select::make('policy.pin_length')
                    ->label($this->label('pin_length'))
                    // Boleh diubah kapan saja: PIN lama tetap bisa dipakai masuk kasir sampai diganti (lihat PosLogin::pinLengthFor).
                    ->helperText(function (): string {
                        $names = self::staffWithOtherPinLength((int) app(Settings::class)->get('policy.pin_length'));

                        return $names === [] ? $this->label('pin_length_help')
                            : $this->label('pin_length_pending', ['names' => implode(', ', $names)]);
                    })
                    ->options(collect([4, 5, 6])->mapWithKeys(fn (int $n) => [$n => __('admin.settings.policy.pin_length_option', ['length' => $n])]))
                    ->required()
                    ->native(false),
                TextInput::make('policy.max_pin_attempts')->label($this->label('max_pin_attempts'))->integer()->required()->minValue(3)->maxValue(10),
                TextInput::make('policy.max_pin_attempts_device')->label($this->label('max_pin_attempts_device'))->helperText($this->label('max_pin_attempts_device_help'))->integer()->required()->minValue(3)->maxValue(100),
                TextInput::make('policy.lock_seconds')->label($this->label('lock_seconds'))->integer()->required()->minValue(30)->maxValue(3600),
                TextInput::make('policy.auto_lock_minutes')->label($this->label('auto_lock_minutes'))->integer()->required()->minValue(0)->maxValue(120),
                TextInput::make('devices.pairing_code_hours')->label($this->label('pairing_code_hours'))->integer()->required()->minValue(1)->maxValue(168),
            ]),
        ];
    }

    /**
     * Karyawan aktif pemakai kasir yang PIN-nya belum sepanjang $length (perlu PIN baru).
     *
     * @return list<string>
     */
    public static function staffWithOtherPinLength(int $length): array
    {
        return User::withPermission(Permission::Pos)
            ->whereNotNull('pin')
            ->where(fn ($q) => $q->whereNull('pin_length')->orWhere('pin_length', '!=', $length))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
