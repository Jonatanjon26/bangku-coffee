<?php

namespace App\Filament\Clusters\Settings\Pages;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

class StoreSettings extends SettingsPage
{
    protected static string $section = 'store';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'toko';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected function keys(): array
    {
        return ['store.name', 'store.phone', 'store.address', 'store.footer', 'printing.cols', 'printing.ticket', 'printing.receipt_default', 'orders.code_prefix'];
    }

    protected function components(): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('store.name')->label($this->label('name'))->required()->maxLength(60),
                TextInput::make('store.phone')->label($this->label('phone'))->tel()->maxLength(30),
                TextInput::make('store.address')->label($this->label('address'))->maxLength(150)->columnSpanFull(),
                TextInput::make('store.footer')->label($this->label('footer'))->maxLength(150)->columnSpanFull(),
                TextInput::make('printing.cols')->label($this->label('cols'))->helperText($this->label('cols_help'))->integer()->required()->minValue(24)->maxValue(48),
                TextInput::make('orders.code_prefix')->label($this->label('code_prefix'))->helperText($this->label('code_prefix_help'))->required()->maxLength(4)->alphaNum(),
                Toggle::make('printing.ticket')->label($this->label('ticket'))->helperText($this->label('ticket_help')),
                Toggle::make('printing.receipt_default')->label($this->label('receipt_default'))->helperText($this->label('receipt_default_help')),
            ]),
        ];
    }
}
