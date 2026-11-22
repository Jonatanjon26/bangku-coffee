<?php

namespace App\Filament\Clusters\Settings\Resources\Reasons;

use App\Enums\ReasonType;
use App\Filament\Clusters\Settings\Resources\Reasons\Pages\ManageReasons;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Models\Reason;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReasonResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = Reason::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string $langKey = 'reasons';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'alasan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.reasons.{$key}");

        return $schema->components([
            Select::make('type')->label($t('type'))->options(ReasonType::options())->required()->native(false),
            TextInput::make('label')->label($t('label_field'))->required()->maxLength(100)->rules([
                fn (Get $get, ?Reason $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    if (Reason::where('type', $get('type'))->where('label', $value)->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                        $fail(__('validation.unique', ['attribute' => __('admin.reasons.label_field')]));
                    }
                },
            ]),
            Toggle::make('requires_note')->label($t('requires_note'))->helperText($t('requires_note_help')),
            Toggle::make('is_active')->label(__('admin.common.active'))->default(true),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.reasons.{$key}");

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->filters([SelectFilter::make('type')->label(__('admin.reasons.type'))->options(ReasonType::options())])
            ->columns([
                TextColumn::make('type')->label($t('type'))->badge()->formatStateUsing(fn (ReasonType $state) => $state->label()),
                TextColumn::make('label')->label($t('label_field'))->searchable()->weight('medium'),
                IconColumn::make('requires_note')->label($t('requires_note'))->boolean(),
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
            'index' => ManageReasons::route('/'),
        ];
    }
}
