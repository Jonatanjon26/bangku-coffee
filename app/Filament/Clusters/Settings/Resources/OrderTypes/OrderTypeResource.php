<?php

namespace App\Filament\Clusters\Settings\Resources\OrderTypes;

use App\Filament\Clusters\Settings\Resources\OrderTypes\Pages\ManageOrderTypes;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Support\Rules;
use App\Models\OrderType;
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

class OrderTypeResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = OrderType::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string $langKey = 'order_types';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'jenis-pesanan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.order_types.{$key}");

        return $schema->components([
            TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(60)->unique(ignoreRecord: true),
            Toggle::make('needs_table')->label($t('needs_table')),
            Toggle::make('applies_service')->label($t('applies_service')),
            Toggle::make('is_active')->label(__('admin.common.active'))->default(true)->rules([Rules::keepOneActive(OrderType::class, __('admin.order_types.min_active'))]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.order_types.{$key}");

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable()->weight('medium'),
                IconColumn::make('needs_table')->label($t('needs_table'))->boolean(),
                IconColumn::make('applies_service')->label($t('applies_service'))->boolean(),
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
            'index' => ManageOrderTypes::route('/'),
        ];
    }
}
