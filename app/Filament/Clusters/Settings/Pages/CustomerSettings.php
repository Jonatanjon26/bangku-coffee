<?php

namespace App\Filament\Clusters\Settings\Pages;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

class CustomerSettings extends SettingsPage
{
    protected static string $section = 'customers';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'pelanggan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected function keys(): array
    {
        return ['customers.enabled', 'customers.ask_phone', 'customers.loyalty_enabled', 'customers.point_per', 'customers.point_value', 'customers.redeem_min_points'];
    }

    protected function components(): array
    {
        return [
            Grid::make(2)->schema([
                Toggle::make('customers.enabled')->label($this->label('enabled')),
                Toggle::make('customers.ask_phone')->label($this->label('ask_phone'))->helperText($this->label('ask_phone_help')),
                Toggle::make('customers.loyalty_enabled')->label($this->label('loyalty_enabled')),
                TextInput::make('customers.point_per')->label($this->label('point_per'))->prefix('Rp')->integer()->required()->minValue(1000),
                TextInput::make('customers.point_value')->label($this->label('point_value'))->helperText($this->label('point_value_help'))->prefix('Rp')->integer()->required()->minValue(1)->maxValue(100000),
                TextInput::make('customers.redeem_min_points')->label($this->label('redeem_min_points'))->integer()->required()->minValue(1)->maxValue(1000000),
            ]),
        ];
    }
}
