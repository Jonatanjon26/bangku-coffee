<?php

namespace App\Filament\Resources\ModifierGroups;

use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Resources\ModifierGroups\Pages\ManageModifierGroups;
use App\Filament\Support\Rules;
use App\Models\ModifierGroup;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ModifierGroupResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = ModifierGroup::class;

    protected static string $langKey = 'modifier_groups';

    protected static string $navigationGroupKey = 'catalog';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'varian';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.modifier_groups.{$key}");

        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(60)->unique(ignoreRecord: true),
                Toggle::make('print_always')->label($t('print_always'))->helperText($t('print_always_help'))->inline(false),
            ]),
            Repeater::make('options')
                ->label($t('options'))
                ->relationship('options')
                ->orderColumn('sort')
                ->addActionLabel($t('add_option'))
                ->minItems(1)
                ->defaultItems(1)
                ->table([
                    Repeater\TableColumn::make($t('option_name')),
                    Repeater\TableColumn::make($t('price_delta')),
                    Repeater\TableColumn::make($t('is_default')),
                    Repeater\TableColumn::make(__('admin.common.active')),
                ])
                ->schema([
                    TextInput::make('name')->label($t('option_name'))->required()->maxLength(60)->distinct(),
                    TextInput::make('price_delta')->label($t('price_delta'))->prefix('Rp')->numeric()->default(0)->required()->rules(Rules::money()),
                    Toggle::make('is_default')->label($t('is_default')),
                    Toggle::make('is_active')->label(__('admin.common.active'))->default(true),
                ])
                // Tepat satu pilihan bawaan (dipilih otomatis di kasir).
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($t): void {
                    if (collect($value)->where('is_default', true)->count() !== 1) {
                        $fail($t('one_default'));
                    }
                }]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.modifier_groups.{$key}");

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable()->weight('medium'),
                TextColumn::make('options.name')->label($t('options'))->badge(),
                IconColumn::make('print_always')->label($t('print_always'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageModifierGroups::route('/'),
        ];
    }
}
