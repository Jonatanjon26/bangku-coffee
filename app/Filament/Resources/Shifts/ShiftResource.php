<?php

namespace App\Filament\Resources\Shifts;

use App\Filament\Concerns\HasTranslatedLabels;
use App\Filament\Resources\Shifts\Pages\ManageShifts;
use App\Models\AuditLog;
use App\Models\Shift;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Shift & kas (mockup admin): modal, penjualan, selisih, setoran ke owner; laporan shift; tandai setoran diterima. */
class ShiftResource extends Resource
{
    use HasTranslatedLabels;

    protected static ?string $model = Shift::class;

    protected static string $langKey = 'shifts';

    protected static string $navigationGroupKey = 'sales';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'shift';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function table(Table $table): Table
    {
        $t = fn (string $key, array $r = []) => __("admin.shifts.{$key}", $r);
        $money = fn ($state) => $state === null ? '–' : 'Rp '.number_format((int) $state, 0, ',', '.');

        return $table
            ->defaultSort('opened_at', 'desc')
            ->columns([
                TextColumn::make('opened_at')->label($t('date'))->dateTime('D, j M Y')->sortable()->description(fn (Shift $r) => $r->opened_at->format('H:i').'–'.($r->closed_at?->format('H:i') ?? '…')),
                TextColumn::make('name')->label($t('shift'))->description(fn (Shift $r) => $r->is_day_close ? $t('day_close') : null),
                TextColumn::make('opener.name')->label($t('cashier')),
                TextColumn::make('device.name')->label($t('device'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('opening_float')->label($t('float'))->formatStateUsing($money)->alignEnd(),
                TextColumn::make('sales_total')->label($t('sales'))->alignEnd()
                    ->state(function (Shift $r): int {
                        $s = $r->isOpen() ? $r->summarize() : (array) $r->summary;

                        return (int) ($s['net'] ?? $s['total'] ?? 0); // bersih (setelah refund); ringkasan lama: kotor
                    })->formatStateUsing($money),
                TextColumn::make('variance')->label($t('variance'))->alignEnd()->badge()
                    ->formatStateUsing(fn ($state) => $state === null ? '–' : ($state === 0 ? '0' : ($state < 0 ? '−' : '+').number_format(abs($state), 0, ',', '.')))
                    ->color(fn ($state) => $state === null ? 'gray' : ($state === 0 ? 'success' : 'danger')),
                TextColumn::make('deposit_amount')->label($t('deposit'))->formatStateUsing($money)->alignEnd(),
                TextColumn::make('status')->label(__('admin.common.status'))->badge()
                    ->state(fn (Shift $r) => $r->isOpen() ? $t('running') : ($r->deposit_received_at ? $t('received') : $t('not_received')))
                    ->color(fn (Shift $r) => $r->isOpen() ? 'info' : ($r->deposit_received_at ? 'success' : 'warning')),
            ])
            ->filters([
                TernaryFilter::make('deposit_received_at')->label($t('received'))->nullable(),
            ])
            ->recordActions([
                Action::make('report')
                    ->label($t('report'))
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('gray')
                    ->visible(fn (Shift $r) => ! $r->isOpen())
                    ->url(fn (Shift $r) => route('admin.shift-report', $r))
                    ->openUrlInNewTab(),
                Action::make('receive')
                    ->label($t('receive'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->authorize(fn (Shift $r) => auth()->user()?->can('receiveDeposit', $r))
                    ->visible(fn (Shift $r) => ! $r->isOpen() && $r->deposit_received_at === null)
                    ->requiresConfirmation()
                    ->modalDescription(fn (Shift $r) => $t('receive_confirm', ['amount' => $money($r->deposit_amount), 'name' => $r->opener?->name]))
                    ->action(function (Shift $r) use ($t) {
                        // Dikunci & dicek ulang: klik ganda/dua admin tidak mencatat dua kali.
                        $done = DB::transaction(function () use ($r) {
                            $shift = Shift::whereKey($r->id)->lockForUpdate()->first();

                            if (! auth()->user()?->can('receiveDeposit', $shift)) {
                                return false;
                            }

                            $shift->forceFill(['deposit_received_by' => auth()->id(), 'deposit_received_at' => now()])->save();
                            AuditLog::record('shift.deposit_received', $shift, null, ['amount' => $shift->deposit_amount]);

                            return true;
                        });

                        $done
                            ? Notification::make()->success()->title($t('received_done'))->send()
                            : Notification::make()->warning()->title($t('receive_unavailable'))->send();
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['opener', 'device']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShifts::route('/'),
        ];
    }
}
