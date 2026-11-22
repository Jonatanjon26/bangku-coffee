<?php

namespace App\Filament\Clusters\Settings\Resources\Denominations;

use App\Filament\Clusters\Settings\Resources\Denominations\Pages\ManageDenominations;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Support\Rules;
use App\Models\Denomination;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DenominationResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = Denomination::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string $langKey = 'denominations';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'pecahan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.denominations.{$key}");

        return $schema->components([
            TextInput::make('value')->label($t('value'))->prefix('Rp')->integer()->required()->minValue(100)->maxValue(1000000)->unique(ignoreRecord: true),
            Toggle::make('is_active')->label(__('admin.common.active'))->default(true)->rules([Rules::keepOneActive(Denomination::class, __('admin.denominations.min_active'))]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.denominations.{$key}");

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->description(__('admin.denominations.description'))
            ->columns([
                TextColumn::make('value')->label($t('value'))->money('IDR', locale: 'id', decimalPlaces: 0)->weight('medium'),
                IconColumn::make('is_active')->label(__('admin.common.active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDenominations::route('/'),
        ];
    }
}
