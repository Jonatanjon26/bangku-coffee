<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Support\Csv;
use App\Support\SalesReport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/** Laporan (mockup admin): omzet bersih per menu, kategori, metode, kasir, jenis pesanan, dan jam. */
class Reports extends Page
{
    protected static ?string $slug = 'laporan';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public const RANGES = ['today', '7', '30', 'month'];

    #[Url]
    public string $range = '7';

    /** Bulan laporan bulanan (Y-m); kosong = bulan berjalan. */
    #[Url]
    public ?string $month = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission(Permission::Reports);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.reports.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('admin.groups.sales');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.reports.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('admin.reports.subheading');
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, self::RANGES, true) ? $range : '7';
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(): array
    {
        return SalesReport::range(in_array($this->range, self::RANGES, true) ? $this->range : '7', month: $this->month);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')
                ->label(__('admin.reports.csv'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => $this->downloadCsv()),
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        [$from, $to] = $this->period();
        $items = app(SalesReport::class)->items($from, $to);
        $t = fn (string $k) => __("admin.reports.{$k}");

        return Csv::download(
            'laporan-menu-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv',
            [$t('item'), $t('category'), $t('qty'), $t('revenue')],
            array_map(fn ($row) => [$row['name'], $row['category'], $row['qty'], $row['total']], $items),
        );
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.reports')->viewData(function () {
                [$from, $to] = $this->period();
                $report = app(SalesReport::class);

                return [
                    'range' => $this->range,
                    'months' => SalesReport::months(),
                    'from' => $from,
                    'to' => $to,
                    'totals' => $report->totals($from, $to),
                    'hourly' => $report->hourly($from, $to),
                    'daily' => $from->equalTo($to) ? [] : $report->daily($from, $to),
                    'categories' => $report->byCategory($from, $to),
                    'methods' => $report->byMethod($from, $to),
                    'cashiers' => $report->byCashier($from, $to),
                    'types' => $report->byOrderType($from, $to),
                    'items' => $report->items($from, $to),
                ];
            }),
        ]);
    }
}
