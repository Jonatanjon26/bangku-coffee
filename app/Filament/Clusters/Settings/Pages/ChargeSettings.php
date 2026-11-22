<?php

namespace App\Filament\Clusters\Settings\Pages;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

class ChargeSettings extends SettingsPage
{
    protected static string $section = 'charges';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'pajak';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected function keys(): array
    {
        return ['charges.service_enabled', 'charges.service_rate', 'charges.service_label', 'charges.tax_enabled', 'charges.tax_rate', 'charges.tax_label'];
    }

    protected function components(): array
    {
        return [
            Grid::make(3)->schema([
                Toggle::make('charges.service_enabled')->label($this->label('service_enabled'))->columnSpanFull(),
                TextInput::make('charges.service_rate')->label($this->label('service_rate'))->numeric()->required()->minValue(0)->maxValue(30)->suffix('%'),
                TextInput::make('charges.service_label')->label($this->label('service_label'))->required()->maxLength(20),
                Toggle::make('charges.tax_enabled')->label($this->label('tax_enabled'))->helperText($this->label('tax_enabled_help'))->columnSpanFull(),
                TextInput::make('charges.tax_rate')->label($this->label('tax_rate'))->numeric()->required()->minValue(0)->maxValue(30)->suffix('%'),
                TextInput::make('charges.tax_label')->label($this->label('tax_label'))->required()->maxLength(20),
            ]),
        ];
    }
}
