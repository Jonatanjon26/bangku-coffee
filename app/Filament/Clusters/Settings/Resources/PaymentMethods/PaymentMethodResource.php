<?php

namespace App\Filament\Clusters\Settings\Resources\PaymentMethods;

use App\Enums\PaymentKind;
use App\Filament\Clusters\Settings\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Support\Rules;
use App\Models\PaymentMethod;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentMethodResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = PaymentMethod::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string $langKey = 'payment_methods';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'metode-bayar';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.payment_methods.{$key}");

        return $schema->components([
            TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(60)->unique(ignoreRecord: true),
            Select::make('kind')->label($t('kind'))->helperText($t('kind_help'))->options(PaymentKind::options())->required()->native(false)->live(),
            Toggle::make('requires_bank')->label($t('requires_bank')),
            FileUpload::make('qr_image')->label($t('qr_image'))->helperText($t('qr_image_help'))
                ->image()->disk('public')->directory('qris')->visibility('public')->maxSize(2048)
                ->visible(fn (Get $get): bool => filled($get('kind')) && $get('kind') !== PaymentKind::Cash->value),
            Toggle::make('is_active')->label(__('admin.common.active'))->default(true)->rules([
                Rules::keepOneActive(PaymentMethod::class, __('admin.payment_methods.min_active')),
                // Hanya satu laci tunai aktif (kembalian & hitung kas shift memakai satu laci).
                fn (Get $get, ?PaymentMethod $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                    $otherCash = PaymentMethod::where('is_active', true)->where('kind', PaymentKind::Cash)->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists();

                    if ($value && $get('kind') === PaymentKind::Cash->value && $otherCash) {
                        $fail(__('admin.payment_methods.one_cash'));
                    }
                },
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.payment_methods.{$key}");

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable()->weight('medium'),
                TextColumn::make('kind')->label($t('kind'))->badge()->formatStateUsing(fn (PaymentKind $state) => $state->label()),
                IconColumn::make('requires_bank')->label($t('requires_bank'))->boolean(),
                ImageColumn::make('qr_image')->label($t('qr_image'))->disk('public')->imageHeight(40),
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
            'index' => ManagePaymentMethods::route('/'),
        ];
    }
}
