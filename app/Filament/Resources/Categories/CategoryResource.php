<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoryResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = Category::class;

    protected static string $langKey = 'categories';

    protected static string $navigationGroupKey = 'catalog';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'kategori';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.categories.{$key}");

        return $schema->components([
            TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(60)->unique(ignoreRecord: true),
            Select::make('station_id')
                ->label($t('station'))
                ->helperText($t('station_help'))
                ->relationship('station', 'name', fn (Builder $query) => $query->orderBy('sort'))
                ->required()
                ->native(false)
                ->createOptionForm([
                    TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(40)->unique('stations', 'name'),
                ]),
            Toggle::make('is_active')->label(__('admin.common.active'))->default(true),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable()->weight('medium'),
                TextColumn::make('station.name')->label(__('admin.categories.station'))->badge(),
                TextColumn::make('menu_items_count')->label(__('admin.categories.menu_count'))->counts('menuItems'),
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
            'index' => ManageCategories::route('/'),
        ];
    }
}
