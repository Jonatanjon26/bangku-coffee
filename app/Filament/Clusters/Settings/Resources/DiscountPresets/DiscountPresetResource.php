<?php

namespace App\Filament\Clusters\Settings\Resources\DiscountPresets;

use App\Enums\DiscountType;
use App\Filament\Clusters\Settings\Resources\DiscountPresets\Pages\ManageDiscountPresets;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Models\DiscountPreset;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DiscountPresetResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = DiscountPreset::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string $langKey = 'discount_presets';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'diskon';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.discount_presets.{$key}");

        return $schema->components([
            Select::make('type')->label($t('type'))->options(DiscountType::options())->required()->native(false)->live(),
            TextInput::make('value')->label($t('value'))->integer()->required()->minValue(1)->rules([
                fn (Get $get, ?DiscountPreset $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    if ($get('type') === DiscountType::Percent->value && (int) $value > 100) {
                        $fail(__('admin.discount_presets.percent_max'));
                    }

                    if (DiscountPreset::where('type', $get('type'))->where('value', (int) $value)->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                        $fail(__('validation.unique', ['attribute' => __('admin.discount_presets.value')]));
                    }
                },
            ]),
            Toggle::make('is_active')->label(__('admin.common.active'))->default(true),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.discount_presets.{$key}");

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('value')->label($t('value'))->weight('medium')
                    ->formatStateUsing(fn (DiscountPreset $record) => $record->type === DiscountType::Percent ? $record->value.'%' : 'Rp '.number_format($record->value, 0, ',', '.')),
                TextColumn::make('type')->label($t('type'))->badge()->formatStateUsing(fn (DiscountType $state) => $state->label()),
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
            'index' => ManageDiscountPresets::route('/'),
        ];
    }
}
