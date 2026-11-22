<?php

namespace App\Filament\Resources\Users;

use App\Enums\Permission;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Support\AttemptLock;
use App\Support\Settings;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

/**
 * Karyawan: PIN kasir (hash, unik, panjang dari pengaturan), email & kata sandi panel (opsional).
 * Tidak bisa dihapus (riwayat transaksi) — nonaktifkan. Semua aturan juga dicek di UserPolicy/RolePolicy.
 */
class UserResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = User::class;

    protected static string $langKey = 'users';

    protected static string $navigationGroupKey = 'relations';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'karyawan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key, array $replace = []) => __("admin.users.{$key}", $replace);
        $pinLength = (int) app(Settings::class)->get('policy.pin_length');
        $isSelf = fn (?User $record): bool => $record !== null && $record->is(auth()->user());

        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(100),
                Select::make('role_id')
                    ->label($t('role'))
                    ->options(fn () => self::assignableRoles()->pluck('name', 'id'))
                    ->native(false)
                    ->live()
                    ->disabled($isSelf)
                    ->helperText(fn (?User $record) => $isSelf($record) ? $t('self_locked') : null)
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($t): void {
                        if ($value && ! self::assignableRoles()->contains('id', (int) $value)) {
                            $fail($t('role_not_allowed'));
                        }
                    }]),
                TextInput::make('pin')
                    ->label($t('pin'))
                    ->password()
                    ->revealable()
                    ->inputMode('numeric')
                    ->autocomplete('new-password')
                    ->helperText(fn (?User $record) => $t($record ? 'pin_help' : 'pin_help_create', ['length' => $pinLength]))
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->regex('/^\d{'.$pinLength.'}$/')
                    ->required(fn (Get $get, ?User $record) => ! $record?->pin && self::roleUsesPos($get('role_id')))
                    ->validationMessages(['required' => $t('pin_required'), 'regex' => $t('pin_length', ['length' => $pinLength])])
                    ->rules([fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if (filled($value) && ($error = self::pinError((string) $value, $record))) {
                            $fail($error);
                        }
                    }]),
                Toggle::make('is_active')
                    ->label($t('is_active'))
                    ->helperText($t('is_active_help'))
                    ->default(true)
                    ->inline(false)
                    ->disabled($isSelf),
                TextInput::make('email')
                    ->label($t('email'))
                    ->helperText($t('email_help'))
                    ->email()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->live(onBlur: true),
                TextInput::make('password')
                    ->label($t('password'))
                    ->helperText($t('password_help', ['min' => config('security.password.min', 12)]))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->rule(Password::default())
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->required(fn (Get $get, ?User $record) => filled($get('email')) && ! $record?->password)
                    ->validationMessages(['required' => $t('password_required')]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.users.{$key}");

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable()->weight('medium')
                    ->description(fn (User $record) => $record->is_admin ? $t('super_admin') : null),
                TextColumn::make('role.name')->label($t('role'))->badge(),
                TextColumn::make('email')->label($t('email'))->searchable()->placeholder('–'),
                // PIN lama (panjang berbeda dari pengaturan) masih berlaku untuk masuk kasir, tetapi perlu diganti.
                TextColumn::make('pin_length')->label($t('pin_set'))->badge()
                    ->state(fn (User $record) => filled($record->pin) ? (int) $record->pin_length : null)
                    ->formatStateUsing(fn (?int $state) => $state === self::currentPinLength() ? $t('pin_ok') : __('admin.users.pin_old', ['length' => $state]))
                    ->color(fn (?int $state) => $state === self::currentPinLength() ? 'success' : 'warning')
                    ->placeholder('–'),
                IconColumn::make('is_active')->label($t('is_active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('role_id')->label($t('role'))->relationship('role', 'name'),
                TernaryFilter::make('is_active')->label($t('is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * "PIN sudah dipakai" membocorkan PIN orang lain (PIN penyetuju dipakai untuk persetujuan di tablet),
     * jadi setiap pengecekan dihitung per pengguna panel dan dibatasi (kunci bertingkat).
     */
    public static function pinError(string $pin, ?User $record): ?string
    {
        $lock = new AttemptLock('admin-pin-check:'.auth()->id());
        $max = (int) config('pos.admin_pin_checks_max');

        if (! $lock->take($max)) {
            return __('admin.users.pin_check_locked', ['minutes' => (int) ceil(max($lock->lockedFor(), 60) / 60)]);
        }

        if ($lock->failures() >= $max && $seconds = $lock->failed($max, (int) app(Settings::class)->get('policy.lock_seconds'))) {
            AuditLog::record('auth.admin_pin_check_locked', $record, null, ['seconds' => $seconds]);
        }

        return User::pinInUse($pin, $record?->id) ? __('admin.users.pin_taken') : null;
    }

    private static function currentPinLength(): int
    {
        return (int) app(Settings::class)->get('policy.pin_length');
    }

    /** Peran yang boleh diberikan pengguna yang sedang masuk (tidak melebihi haknya sendiri). */
    public static function assignableRoles()
    {
        $user = auth()->user();

        return Role::with('rolePermissions')->orderBy('sort')->get()
            ->filter(fn (Role $role) => $user && RolePolicy::grantable($user, $role->permissions()))
            ->values();
    }

    private static function roleUsesPos(mixed $roleId): bool
    {
        return (bool) ($roleId ? Role::find($roleId)?->hasPermission(Permission::Pos) : false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
