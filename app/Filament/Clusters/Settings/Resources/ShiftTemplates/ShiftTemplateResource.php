<?php

namespace App\Filament\Clusters\Settings\Resources\ShiftTemplates;

use App\Filament\Clusters\Settings\Resources\ShiftTemplates\Pages\ManageShiftTemplates;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Support\Rules;
use App\Models\ShiftTemplate;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShiftTemplateResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = ShiftTemplate::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string $langKey = 'shift_templates';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'shift';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.shift_templates.{$key}");

        return $schema->components([
            TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(60)->unique(ignoreRecord: true),
            TimePicker::make('starts_at')->label($t('starts_at'))->seconds(false)->required(),
            TimePicker::make('ends_at')->label($t('ends_at'))->seconds(false)->required(),
            Toggle::make('is_active')->label(__('admin.common.active'))->default(true)->rules([Rules::keepOneActive(ShiftTemplate::class, __('admin.shift_templates.min_active'))]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.shift_templates.{$key}");

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->description(__('admin.shift_templates.description'))
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable()->weight('medium'),
                TextColumn::make('starts_at')->label($t('starts_at'))->time('H:i'),
                TextColumn::make('ends_at')->label($t('ends_at'))->time('H:i'),
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
            'index' => ManageShiftTemplates::route('/'),
        ];
    }
}
