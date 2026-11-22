<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\ReasonType;
use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Resources\Orders\Pages\ManageOrders;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Support\OrderCorrections;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** Transaksi (penjualan & open bill): daftar, detail, refund utuh, batal open bill. Tidak bisa diubah/dihapus. */
class OrderResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = Order::class;

    protected static string $langKey = 'orders';

    protected static string $navigationGroupKey = 'sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'transaksi';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    public static function infolist(Schema $schema): Schema
    {
        $t = fn (string $key) => __("admin.orders.{$key}");
        $rp = fn ($state) => 'Rp '.number_format((int) $state, 0, ',', '.');

        return $schema->components([
            Grid::make(4)->schema([
                TextEntry::make('code')->label($t('code')),
                TextEntry::make('status')->label(__('admin.common.status'))->badge()->formatStateUsing(fn (OrderStatus $state) => $state->label()),
                TextEntry::make('customer_name')->label($t('customer'))->placeholder('–'),
                TextEntry::make('order_type_name')->label($t('type'))->suffix(fn (Order $record) => $record->table_number ? ' · '.__('pos.held.table', ['table' => $record->table_number]) : ''),
                TextEntry::make('created_at')->label($t('created_at'))->dateTime('j M Y H:i'),
                TextEntry::make('paid_at')->label($t('paid_at'))->dateTime('j M Y H:i')->placeholder('–'),
                TextEntry::make('creator.name')->label($t('created_by')),
                TextEntry::make('cashier.name')->label($t('paid_by'))->placeholder('–'),
            ]),
            RepeatableEntry::make('items')->label($t('items'))->table([
                TableColumn::make($t('item')),
                TableColumn::make($t('qty')),
                TableColumn::make($t('line_total')),
            ])->schema([
                TextEntry::make('name')->label($t('item'))->formatStateUsing(function ($state, $record) {
                    $labels = implode(' · ', $record->labels());

                    return $labels !== '' ? $state.' ('.$labels.')' : $state;
                }),
                TextEntry::make('quantity')->label($t('qty')),
                TextEntry::make('unit_price')->label($t('line_total'))->formatStateUsing(fn ($state, $record) => $rp($state * $record->quantity)),
            ]),
            Grid::make(4)->schema([
                TextEntry::make('subtotal')->label($t('subtotal'))->formatStateUsing($rp),
                TextEntry::make('discount_amount')->label($t('discount'))->formatStateUsing($rp),
                TextEntry::make('points_discount')->label(fn (Order $record) => __('pos.print.points', ['points' => $record->points_redeemed]))->formatStateUsing($rp)->visible(fn (Order $record) => $record->points_discount > 0),
                TextEntry::make('service_amount')->label(fn () => app(Settings::class)->get('charges.service_label'))->formatStateUsing($rp),
                TextEntry::make('tax_amount')->label(fn () => app(Settings::class)->get('charges.tax_label'))->formatStateUsing($rp),
                TextEntry::make('total')->label($t('total'))->formatStateUsing($rp)->weight('bold'),
                TextEntry::make('payment_summary')->label($t('payment'))->state(fn (Order $record) => $record->payments->map(fn ($p) => trim($p->method_name.' '.$p->bank_name).' · '.$rp($p->received).($p->change ? ' · '.$t('change').' '.$rp($p->change) : ''))->implode(', '))->placeholder('–')->columnSpan(3),
            ]),
            Section::make($t('corrections'))
                ->visible(fn (Order $record) => $record->refunds->isNotEmpty() || $record->status === OrderStatus::Voided)
                ->schema([
                    TextEntry::make('refund_info')->hiddenLabel()->state(fn (Order $record) => $record->refunds->map(fn ($r) => __('admin.orders.refund_info', ['amount' => $rp($r->amount), 'reason' => $r->reason_label, 'by' => $r->refundedBy?->name, 'approver' => $r->approver?->name ?? '–', 'time' => $r->created_at->format('j M Y H:i')]))->implode("\n"))->visible(fn (Order $record) => $record->refunds->isNotEmpty()),
                    TextEntry::make('void_info')->hiddenLabel()->state(fn (Order $record) => $record->voided_at ? __('admin.orders.void_info', ['reason' => $record->void_reason_label, 'note' => $record->void_note ?? '', 'time' => $record->voided_at->format('j M Y H:i')]) : null)->visible(fn (Order $record) => $record->status === OrderStatus::Voided),
                ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        $t = fn (string $key) => __("admin.orders.{$key}");

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->label($t('code'))->searchable()->fontFamily('mono')->description(fn (Order $record) => '#'.$record->queue_number),
                TextColumn::make('created_at')->label($t('time'))->dateTime('j M H:i')->sortable()
                    ->description(fn (Order $record) => $record->paid_at && ! $record->paid_at->isSameDay($record->created_at) ? $t('paid_at').' '.$record->paid_at->format('j M H:i') : null),
                TextColumn::make('customer_name')->label($t('customer'))->searchable()->placeholder('–'),
                TextColumn::make('order_type_name')->label($t('type'))->toggleable(),
                TextColumn::make('payments.method_name')->label($t('payment'))->badge()->placeholder('–'),
                TextColumn::make('cashier.name')->label($t('paid_by'))->placeholder('–')->toggleable(),
                TextColumn::make('total')->label($t('total'))->money('IDR', locale: 'id', decimalPlaces: 0)->sortable(),
                TextColumn::make('status')->label(__('admin.common.status'))->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => match ($state) {
                        OrderStatus::Paid => 'success', OrderStatus::Open => 'warning', default => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.common.status'))->options(OrderStatus::options()),
                Filter::make('date')->label($t('date'))
                    ->schema([DatePicker::make('from')->label($t('from')), DatePicker::make('until')->label($t('until'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where(fn ($w) => $w->whereDate('paid_business_date', '>=', $d)->orWhere(fn ($o) => $o->whereNull('paid_business_date')->whereDate('business_date', '>=', $d))))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where(fn ($w) => $w->whereDate('paid_business_date', '<=', $d)->orWhere(fn ($o) => $o->whereNull('paid_business_date')->whereDate('business_date', '<=', $d))))),
                SelectFilter::make('method')->label($t('payment'))
                    ->options(fn () => PaymentMethod::orderBy('sort')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->whereHas('payments', fn ($p) => $p->where('payment_method_id', $id)))),
            ])
            ->recordActions([
                ViewAction::make()->modalWidth('3xl'),
                Action::make('refund')
                    ->label($t('refund'))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->authorize(fn (Order $record) => auth()->user()?->can('refund', $record))
                    ->visible(fn (Order $record) => $record->refundableToday())
                    ->modalDescription(fn (Order $record) => __('admin.orders.refund_confirm', ['code' => $record->code, 'total' => 'Rp '.number_format($record->total, 0, ',', '.')]))
                    ->schema(fn () => self::reasonForm(ReasonType::Refund))
                    ->action(function (Order $record, array $data) use ($t) {
                        $corrections = app(OrderCorrections::class);
                        $user = auth()->user();

                        try {
                            $drawer = $corrections->drawerFor($record);
                            $corrections->refund($record, $user, $user->hasPermission(Permission::Approve) ? $user : null, (int) $data['reason_id'], $data['note'] ?? null, $drawer);
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

                            return;
                        }

                        Notification::make()->success()->title($t('refunded'))->body($drawer ? null : $t('no_drawer'))->send();
                    }),
                Action::make('void')
                    ->label($t('void'))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->authorize(fn (Order $record) => auth()->user()?->can('void', $record))
                    ->visible(fn (Order $record) => $record->status === OrderStatus::Open)
                    ->modalDescription(fn (Order $record) => __('admin.orders.void_confirm', ['code' => $record->code, 'total' => 'Rp '.number_format($record->total, 0, ',', '.')]))
                    ->schema(fn () => self::reasonForm(ReasonType::Void))
                    ->action(function (Order $record, array $data) use ($t) {
                        try {
                            app(OrderCorrections::class)->voidOpenBill($record, auth()->user(), auth()->user(), (int) $data['reason_id'], $data['note'] ?? null);
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

                            return;
                        }

                        Notification::make()->success()->title($t('voided'))->send();
                    }),
            ]);
    }

    /** @return list<Field> */
    private static function reasonForm(ReasonType $type): array
    {
        return [
            Select::make('reason_id')->label(__('pos.corrections.reason'))->options(Reason::active()->ofType($type)->pluck('label', 'id'))->required()->native(false)->live(),
            TextInput::make('note')->label(__('pos.corrections.note'))->maxLength(OrderCorrections::NOTE_MAX)
                ->required(fn ($get) => (bool) Reason::find($get('reason_id'))?->requires_note),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['payments', 'cashier', 'creator', 'items.modifiers', 'refunds.refundedBy', 'refunds.approver']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageOrders::route('/'),
        ];
    }
}
