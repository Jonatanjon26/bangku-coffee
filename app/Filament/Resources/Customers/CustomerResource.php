<?php

namespace App\Filament\Resources\Customers;

use App\Enums\OrderStatus;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Resources\Customers\Pages\ManageCustomers;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Customers;
use App\Support\Settings;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pelanggan (mockup admin › Pelanggan): cari nama/no. HP, kunjungan, belanja, poin, persetujuan dihubungi,
 * riwayat, hapus data atas permintaan (transaksi tetap ada tanpa data pribadi), unduh CSV.
 */
class CustomerResource extends Resource
{
    use HasTranslatedLabels;

    public const HISTORY_LIMIT = 8;

    protected static ?string $model = Customer::class;

    protected static string $langKey = 'customers';

    protected static string $navigationGroupKey = 'relations';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'pelanggan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function form(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.customers.{$key}");

        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(Customers::NAME_MAX),
                TextInput::make('phone')->label($t('phone'))->tel()->maxLength(Customers::PHONE_INPUT_MAX)->placeholder('08…')
                    ->rules([fn (?Customer $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $phone = Customer::normalizePhone($value);

                        if ($phone === null) {
                            return;
                        }

                        if (! Customers::validPhone($phone)) {
                            $fail(__('pos.customers.errors.phone'));
                        } elseif (Customer::where('phone', $phone)->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                            $fail(__('pos.customers.errors.phone_taken'));
                        }
                    }]),
                TextInput::make('notes')->label($t('notes'))->maxLength(Customers::NOTES_MAX)->columnSpanFull(),
                Toggle::make('contact_consent')->label(__('pos.customers.consent'))->columnSpanFull(),
            ]),
        ])->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        $t = fn (string $key, array $r = []) => __("admin.customers.{$key}", $r);
        $rp = fn ($state) => 'Rp '.number_format((int) $state, 0, ',', '.');

        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('phone')->label($t('phone'))->placeholder('–'),
                TextEntry::make('visits_count')->label($t('visits')),
                TextEntry::make('total_spent')->label($t('spent'))->formatStateUsing($rp),
                TextEntry::make('points')->label($t('points'))->visible(fn () => (bool) app(Settings::class)->get('customers.loyalty_enabled')),
                TextEntry::make('last_visit_at')->label($t('last_visit'))->dateTime('j M Y H:i')->placeholder('–'),
                TextEntry::make('created_at')->label($t('since'))->date('F Y'),
                TextEntry::make('contact_consent')->label($t('consent'))->formatStateUsing(fn ($state) => $t($state ? 'yes' : 'no')),
                TextEntry::make('notes')->label($t('notes'))->placeholder('–')->columnSpan(2),
            ]),
            RepeatableEntry::make('history')->label($t('history', ['count' => self::HISTORY_LIMIT]))
                ->state(fn (Customer $record) => $record->orders()->where('status', OrderStatus::Paid)->with('items')
                    ->latest('paid_at')->limit(self::HISTORY_LIMIT)->get())
                ->placeholder($t('no_history'))
                ->table([
                    TableColumn::make($t('date')),
                    TableColumn::make(__('admin.orders.code')),
                    TableColumn::make($t('items')),
                    TableColumn::make($t('total')),
                ])
                ->schema([
                    TextEntry::make('paid_at')->dateTime('j M Y H:i'),
                    TextEntry::make('code')->fontFamily('mono'),
                    TextEntry::make('items_label')->state(fn (Order $record) => $record->items->map(fn ($i) => $i->quantity.'× '.$i->name)->implode(', ')),
                    TextEntry::make('total')->formatStateUsing($rp),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.customers.{$key}");
        $loyalty = fn () => (bool) app(Settings::class)->get('customers.loyalty_enabled');

        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc(DB::raw('last_visit_at IS NOT NULL'))->orderByDesc('last_visit_at')->orderBy('name'))
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable()->sortable()->weight('medium')->wrap()->description(fn (Customer $record) => $record->notes),
                TextColumn::make('phone')->label($t('phone'))->fontFamily('mono')->placeholder('–')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('phone', 'like', '%'.(Customer::normalizePhone($search) ?? $search).'%')),
                TextColumn::make('visits_count')->label($t('visits'))->numeric()->sortable()->alignEnd(),
                TextColumn::make('total_spent')->label($t('spent'))->money('IDR', locale: 'id', decimalPlaces: 0)->sortable()->alignEnd(),
                TextColumn::make('points')->label($t('points'))->numeric()->sortable()->alignEnd()->visible($loyalty),
                TextColumn::make('last_visit_at')->label($t('last_visit'))->date('j M Y')->sortable()->placeholder('–'),
                TextColumn::make('contact_consent')->label($t('consent'))->badge()
                    ->formatStateUsing(fn (bool $state) => $t($state ? 'yes' : 'no'))
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('contact_consent')->label($t('consent')),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('3xl')
                    ->modalHeading(fn (Customer $record) => $record->name)
                    ->modalDescription($t('view_sub')),
                EditAction::make()->using(fn (Customer $record, array $data) => self::persist($record, $data)),
                Action::make('erase')
                    ->label($t('erase'))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->authorize(fn (Customer $record) => auth()->user()?->can('delete', $record))
                    ->requiresConfirmation()
                    ->modalHeading($t('erase_title'))
                    ->modalDescription(fn (Customer $record) => __('admin.customers.erase_confirm', ['name' => $record->name]))
                    ->modalSubmitActionLabel($t('erase'))
                    ->action(function (Customer $record) use ($t) {
                        $record->erase(auth()->user());
                        Notification::make()->success()->title($t('erased'))->send();
                    }),
            ]);
    }

    /** Simpan dari form admin lewat validasi yang sama dengan kasir (nama, no. HP dinormalisasi, catatan). */
    public static function persist(Customer $customer, array $data): Customer
    {
        $customer->fill(app(Customers::class)->validated($data, $customer))->save();

        return $customer;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCustomers::route('/'),
        ];
    }
}
