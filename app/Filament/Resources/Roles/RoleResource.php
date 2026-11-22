<?php

namespace App\Filament\Resources\Roles;

use App\Enums\Permission;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Models\Role;
use App\Policies\RolePolicy;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Peran & matriks hak akses. Hak yang bisa diberikan tidak melebihi hak pengguna yang mengubah. */
class RoleResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = Role::class;

    protected static string $langKey = 'roles';

    protected static string $navigationGroupKey = 'relations';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'peran';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.roles.{$key}");

        return $schema->components([
            TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(60)->unique(ignoreRecord: true),
            TextInput::make('description')->label($t('description'))->maxLength(255),
            CheckboxList::make('permissions')
                ->label($t('permissions'))
                ->helperText($t('permissions_help'))
                ->options(Permission::options())
                ->disableOptionWhen(fn (string $value) => ! RolePolicy::grantable(auth()->user(), [Permission::from($value)]))
                ->afterStateHydrated(fn (CheckboxList $component, ?Role $record) => $component->state(
                    $record ? array_map(fn (Permission $p) => $p->value, $record->permissions()) : []
                ))
                ->rules([fn (?Role $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $t): void {
                    // Hanya hak yang ditambahkan yang harus dimiliki pengubah; hak lama yang tidak tersentuh boleh tetap.
                    $old = $record ? array_map(fn (Permission $p) => $p->value, $record->permissions()) : [];
                    $added = array_map(fn (mixed $v) => Permission::tryFrom((string) $v), array_diff((array) $value, $old));

                    if (in_array(null, $added, true)) {
                        $fail(__('validation.in', ['attribute' => $t('permissions')]));

                        return;
                    }

                    if (! RolePolicy::grantable(auth()->user(), $added)) {
                        $fail($t('permission_not_allowed'));
                    }
                }])
                ->dehydrated(false)
                ->saveRelationshipsUsing(fn (Role $record, ?array $state) => $record->syncPermissions(array_map(fn (string $v) => Permission::from($v), $state ?? [])))
                ->columns(2),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.roles.{$key}");

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable()->weight('medium')->description(fn (Role $record) => $record->description),
                TextColumn::make('permissions')
                    ->label($t('permissions'))
                    ->badge()
                    ->getStateUsing(fn (Role $record) => array_map(fn (Permission $p) => $p->label(), $record->permissions()))
                    ->wrap(),
                TextColumn::make('users_count')->label($t('users_count'))->counts('users'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRoles::route('/'),
        ];
    }
}
