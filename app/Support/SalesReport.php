<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Angka dasbor & laporan. Penjualan dihitung pada tanggal bayar (paid_business_date);
 * omzet = transaksi dibayar yang tidak direfund (sama dengan mockup admin). Refund dilaporkan terpisah.
 */
class SalesReport
{
    /** Berapa hari ke belakang dicek untuk settlement EDC yang terlewat. */
    public const EDC_LOOKBACK_DAYS = 14;

    /** Banyak bulan (termasuk bulan berjalan) yang bisa dipilih di laporan bulanan. */
    public const MONTHS_BACK = 24;

    /**
     * Rentang dari pilihan cepat: today | 7 | 30 | month. Untuk "month", $month (Y-m) memilih bulan lain;
     * bulan berjalan dipotong sampai hari ini, bulan di luar daftar pilihan diabaikan.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $preset, ?CarbonImmutable $now = null, ?string $month = null): array
    {
        $today = BusinessDate::today($now);

        if ($preset === 'month' && array_key_exists((string) $month, self::months($now))) {
            $start = CarbonImmutable::createFromFormat('!Y-m', $month, $today->getTimezone());

            return [$start, $start->endOfMonth()->startOfDay()->min($today)];
        }

        return match ($preset) {
            '7' => [$today->subDays(6), $today],
            '30' => [$today->subDays(29), $today],
            'month' => [$today->startOfMonth(), $today],
            default => [$today, $today],
        };
    }

    /** @return array<string, string> pilihan bulan terbaru dulu, "Y-m" => "November 2026" */
    public static function months(?CarbonImmutable $now = null): array
    {
        $current = BusinessDate::today($now)->startOfMonth();
        $months = [];

        for ($i = 0; $i < self::MONTHS_BACK; $i++) {
            $month = $current->subMonthsNoOverflow($i);
            $months[$month->format('Y-m')] = $month->translatedFormat('F Y');
        }

        return $months;
    }

    /** Transaksi yang dihitung sebagai omzet pada rentang tanggal bayar. */
    public function sales(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Order::query()
            ->where('status', OrderStatus::Paid)
            ->whereDate('paid_business_date', '>=', $from->toDateString())
            ->whereDate('paid_business_date', '<=', $to->toDateString());
    }

    /** @return array{total: int, count: int, average: int, discount: int, refunds: int, refund_count: int} */
    public function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sales = $this->sales($from, $to);
        $total = (int) (clone $sales)->sum('total');
        $count = (clone $sales)->count();
        $refunds = Refund::whereDate('business_date', '>=', $from->toDateString())->whereDate('business_date', '<=', $to->toDateString());

        return [
            'total' => $total,
            'count' => $count,
            'average' => $count ? intdiv($total, $count) : 0,
            'discount' => (int) (clone $sales)->sum('discount_amount'),
            'refunds' => (int) (clone $refunds)->sum('amount'),
            'refund_count' => (clone $refunds)->count(),
        ];
    }

    /** Omzet per hari (termasuk hari tanpa penjualan). @return list<array{label: string, date: string, value: int}> */
    public function daily(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->sales($from, $to)->selectRaw('paid_business_date as d, SUM(total) as t')->groupBy('paid_business_date')->pluck('t', 'd')->all();
        $out = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $out[] = ['label' => $day->translatedFormat('D'), 'date' => $day->toDateString(), 'value' => (int) ($rows[$day->toDateString()] ?? 0)];
        }

        return $out;
    }

    /** Omzet per jam bayar. @return array<string, int> "07" => …, hanya jam yang ada penjualan */
    public function hourly(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];

        foreach ($this->sales($from, $to)->get(['paid_at', 'total']) as $order) {
            $hour = $order->paid_at->format('H');
            $out[$hour] = ($out[$hour] ?? 0) + $order->total;
        }

        ksort($out);

        return $out;
    }

    /** @return array<string, int> metode (+ bank) => omzet, terbesar dulu */
    public function byMethod(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->sorted(Payment::whereIn('order_id', $this->sales($from, $to)->select('id'))
            ->get(['method_name', 'bank_name', 'amount'])
            ->groupBy(fn ($p) => trim($p->method_name.' '.$p->bank_name))
            ->map->sum('amount')->all());
    }

    /** @return array<string, int> */
    public function byCashier(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->sorted($this->sales($from, $to)->with('cashier')->get(['paid_by', 'total'])
            ->groupBy(fn ($o) => $o->cashier?->name ?? '-')->map->sum('total')->all());
    }

    /** @return array<string, int> */
    public function byOrderType(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->sorted($this->sales($from, $to)->selectRaw('order_type_name as k, SUM(total) as t')->groupBy('order_type_name')->pluck('t', 'k')->map(fn ($v) => (int) $v)->all());
    }

    /** Omzet item (harga item × jumlah, sebelum diskon/pajak) per kategori. @return array<string, int> */
    public function byCategory(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->sorted(OrderItem::whereIn('order_id', $this->sales($from, $to)->select('id'))
            ->selectRaw('COALESCE(category_name, ?) as k, SUM(unit_price * quantity) as t', ['-'])
            ->groupBy('category_name')->pluck('t', 'k')->map(fn ($v) => (int) $v)->all());
    }

    /** @return list<array{name: string, category: string, qty: int, total: int}> terlaris (omzet) dulu */
    public function items(CarbonImmutable $from, CarbonImmutable $to, ?int $limit = null): array
    {
        return OrderItem::whereIn('order_id', $this->sales($from, $to)->select('id'))
            ->selectRaw('name, COALESCE(category_name, ?) as category, SUM(quantity) as qty, SUM(unit_price * quantity) as total', ['-'])
            ->groupBy('name', 'category_name')
            ->orderByDesc(DB::raw('SUM(unit_price * quantity)'))
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'category' => $r->category, 'qty' => (int) $r->qty, 'total' => (int) $r->total])
            ->all();
    }

    /** @return list<array{name: string, qty: int}> terlaris menurut jumlah */
    public function topItemsByQty(CarbonImmutable $from, CarbonImmutable $to, int $limit = 5): array
    {
        return OrderItem::whereIn('order_id', $this->sales($from, $to)->select('id'))
            ->selectRaw('name, SUM(quantity) as qty')->groupBy('name')->orderByDesc(DB::raw('SUM(quantity)'))->limit($limit)->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->qty])->all();
    }

    /** Ringkasan dasbor hari ini. @return array<string, mixed> */
    public function dashboard(?CarbonImmutable $now = null): array
    {
        $today = BusinessDate::today($now);
        $yesterday = $today->subDay();
        $todayTotals = $this->totals($today, $today);
        $closedToday = Shift::whereNotNull('closed_at')->whereDate('business_date', $today->toDateString())->with('opener')->orderBy('closed_at')->get();
        $open = Order::open();
        $maxDays = (int) app(Settings::class)->get('policy.open_bill_max_days');

        return [
            'today' => $todayTotals,
            'yesterday' => $this->totals($yesterday, $yesterday)['total'],
            'week' => $this->daily($today->subDays(6), $today),
            'methods' => $this->byMethod($today, $today),
            'top' => $this->topItemsByQty($today, $today),
            'customers' => Customer::count(),
            'member_orders' => $this->sales($today, $today)->whereNotNull('customer_id')->count(),
            'variance' => (int) $closedToday->sum('variance'),
            'closed_shifts' => $closedToday,
            'running_shifts' => Shift::running()->with('opener', 'device')->get(),
            'open_bills' => ['count' => (clone $open)->count(), 'total' => (int) (clone $open)->sum('total'),
                'overdue' => (clone $open)->whereDate('business_date', '<', $today->subDays($maxDays)->toDateString())->exists()],
            'edc_unsettled' => $this->unsettledEdcDates($today),
        ];
    }

    /**
     * Hari dagang sebelum hari ini yang punya pembayaran EDC (selain refund hari yang sama — di-void di mesin)
     * tetapi belum dicocokkan dengan settlement mesin EDC (tutup hari terlewat).
     *
     * @return list<CarbonImmutable>
     */
    public function unsettledEdcDates(CarbonImmutable $today, int $days = self::EDC_LOOKBACK_DAYS): array
    {
        return Payment::where('method_kind', PaymentKind::Edc)
            ->whereDate('business_date', '>=', $today->subDays($days)->toDateString())
            ->whereDate('business_date', '<', $today->toDateString())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('refunds')
                ->whereColumn('refunds.order_id', 'payments.order_id')->whereColumn('refunds.business_date', 'payments.business_date'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('edc_settlements')->whereColumn('edc_settlements.business_date', 'payments.business_date'))
            ->distinct()->orderByDesc('business_date')->pluck('business_date')
            ->map(fn ($d) => CarbonImmutable::parse($d))->values()->all();
    }

    /** @param  array<string, int|float>  $rows */
    private function sorted(array $rows): array
    {
        $rows = array_map('intval', $rows);
        arsort($rows);

        return $rows;
    }
}
