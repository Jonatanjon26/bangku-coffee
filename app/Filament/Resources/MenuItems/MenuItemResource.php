<?php

namespace App\Filament\Resources\MenuItems;

use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Resources\MenuItems\Pages\ManageMenuItems;
use App\Filament\Support\Rules;
use App\Models\MenuItem;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MenuItemResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = MenuItem::class;

    protected static string $langKey = 'menu_items';

    protected static string $navigationGroupKey = 'catalog';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'menu';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.menu_items.{$key}");

        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(100),
                Select::make('category_id')->label($t('category'))->relationship('category', 'name', fn (Builder $query) => $query->orderBy('sort'))->required()->native(false),
                TextInput::make('price')->label(__('admin.common.price'))->prefix('Rp')->numeric()->required()->rules(Rules::money()),
                Select::make('icon')->label($t('icon'))->helperText($t('icon_help'))
                    ->options(fn (?MenuItem $record) => self::iconOptions($record?->icon))
                    ->searchable()->native(false)->searchPrompt($t('icon_search')),
            ]),
            FileUpload::make('image_path')
                ->label($t('image'))
                ->helperText($t('image_help'))
                ->image()
                // Tanpa SVG (bisa berisi skrip).
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(2048)
                ->disk('public')
                ->directory('menu')
                ->visibility('public'),
            CheckboxList::make('modifierGroups')
                ->label($t('modifier_groups'))
                ->helperText($t('modifier_groups_help'))
                ->relationship('modifierGroups', 'name', fn (Builder $query) => $query->orderBy('sort'))
                ->columns(4),
            Grid::make(3)->schema([
                Toggle::make('is_active')->label($t('is_active'))->default(true),
                Toggle::make('is_sold_out')->label($t('is_sold_out')),
                Toggle::make('is_best_seller')->label($t('is_best_seller')),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.menu_items.{$key}");
        $cannotEdit = fn (MenuItem $record): bool => ! (auth()->user()?->can('update', $record) ?? false);

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->square()->size(40),
                TextColumn::make('icon')->label(''),
                TextColumn::make('name')->label(__('admin.common.name'))->searchable()->weight('medium'),
                TextColumn::make('category.name')->label($t('category'))->sortable(),
                TextColumn::make('price')->label(__('admin.common.price'))->money('IDR', locale: 'id', decimalPlaces: 0)->sortable(),
                TextColumn::make('modifierGroups.name')->label($t('modifier_groups'))->badge()->toggleable(),
                ToggleColumn::make('is_active')->label($t('is_active'))->disabled($cannotEdit),
                ToggleColumn::make('is_sold_out')->label($t('is_sold_out'))->disabled($cannotEdit),
                ToggleColumn::make('is_best_seller')->label($t('is_best_seller'))->disabled($cannotEdit)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category_id')->label($t('category'))->relationship('category', 'name'),
                TernaryFilter::make('is_sold_out')->label($t('is_sold_out')),
                TrashedFilter::make()->label($t('trashed')),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    DeleteAction::make(),
                    RestoreAction::make(),
                ]),
            ]);
    }

    /**
     * Daftar pilihan ikon (lang admin.menu_items.icons). Ikon lama di luar daftar tetap jadi pilihan untuk
     * menu itu sendiri, supaya tidak hilang saat diedit.
     *
     * @return array<string, string>
     */
    public static function iconOptions(?string $current = null): array
    {
        $options = collect(__('admin.menu_items.icons'))->mapWithKeys(fn (string $label, string $emoji) => [$emoji => $emoji.'  '.$label])->all();

        if (filled($current) && ! isset($options[$current])) {
            $options = [$current => $current] + $options;
        }

        return $options;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMenuItems::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['category', 'modifierGroups']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
