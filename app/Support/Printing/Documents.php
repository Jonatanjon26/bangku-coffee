<?php

namespace App\Support\Printing;

use App\Enums\CashMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Models\EdcSettlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shift;
use App\Models\User;
use App\Support\Settings;

/**
 * Isi cetakan (tiket barista, struk, laporan shift, tes printer) sebagai baris teks + gaya,
 * sama dengan mockup kasir. Lebar dari pengaturan printing.cols; teks dari lang/{locale}/pos.php (print.*).
 *
 * Dokumen: ['cols' => int, 'lines' => [['text' => string, 'style' => ?'big'|'big-c'|'b']]]
 */
class Documents
{
    public function __construct(private readonly Settings $settings) {}

    public function cols(): int
    {
        return max(24, min(48, (int) $this->settings->get('printing.cols')));
    }

    /**
     * Tiket barista: nomor & nama besar, item tebal dengan varian.
     *
     * @param  list<array{name: string, labels: list<string>, qty: int}>|null  $items  null = semua item pesanan
     */
    public function ticket(Order $order, ?array $items = null, bool $addition = false, bool $reprint = false): ?array
    {
        $items ??= $order->items()->with('modifiers')->get()->map(fn (OrderItem $i) => ['name' => $i->name, 'labels' => $i->labels(), 'qty' => $i->quantity])->all();
        $items = array_values(array_filter($items, fn ($i) => $i['qty'] > 0));

        if ($items === []) {
            return null;
        }

        $t = new TextLayout($this->cols());
        $half = intdiv($t->cols, 2);
        $out = [];
        $add = function (array $lines, ?string $style = null) use (&$out) {
            foreach ($lines as $text) {
                $out[] = $style ? ['text' => $text, 'style' => $style] : ['text' => $text];
            }
        };

        if ($reprint) {
            $add($t->center(__('pos.print.reprint')));
        }

        $add($t->center(__($addition ? 'pos.print.ticket_addition' : 'pos.print.ticket')));
        $add($t->center('#'.$order->queue_number, $half), 'big');

        if ($order->customer_name) {
            $add($t->center(mb_strtoupper($order->customer_name), $half), 'big');
        }

        $add($t->row($this->where($order), now()->format('H:i')), 'b');
        $out[] = ['text' => $t->rule()];

        foreach ($items as $item) {
            $add($t->row($item['qty'].'x '.$item['name']), 'b');

            if ($item['labels']) {
                $add($t->row('   '.implode(', ', $item['labels'])));
            }
        }

        $out[] = ['text' => $t->rule()];
        $add($t->row(__('pos.print.items', ['count' => array_sum(array_column($items, 'qty'))]), $order->code));

        return ['cols' => $t->cols, 'lines' => $out];
    }

    /** Struk pelanggan. */
    public function receipt(Order $order, bool $reprint = false): array
    {
        $order->loadMissing(['items.modifiers', 'payments', 'cashier', 'creator']);
        $t = new TextLayout($this->cols());
        $s = $this->settings;
        $out = [];
        $add = function (array $lines, ?string $style = null) use (&$out) {
            foreach ($lines as $text) {
                $out[] = $style ? ['text' => $text, 'style' => $style] : ['text' => $text];
            }
        };
        $paidAt = $order->paid_at ?? $order->created_at;

        $add($t->center((string) $s->get('store.name')), 'big-c');

        foreach (['store.address', 'store.phone'] as $key) {
            if (filled($s->get($key))) {
                $add($t->center((string) $s->get($key)));
            }
        }

        if ($reprint) {
            $add($t->center(__('pos.print.reprint')));
        }

        if ($order->status === OrderStatus::Refunded) {
            $add($t->center(__('pos.print.refunded')), 'b');
        }

        $out[] = ['text' => $t->rule()];
        $add($t->row(__('pos.print.order_no', ['code' => $order->code]), $paidAt->format('d/m/Y')));
        $add($t->row(__('pos.print.cashier', ['name' => ($order->cashier ?? $order->creator)?->name ?? '-']), $paidAt->format('H:i')));
        $add($t->row(__('pos.print.queue', ['queue' => $order->queue_number]).($order->customer_name ? ' - '.$order->customer_name : '')), 'b');
        $add($t->row($this->where($order)));
        $out[] = ['text' => $t->rule()];

        foreach ($order->items as $item) {
            $add($t->row($item->name));
            $add($t->row('  '.$item->quantity.' x '.self::money($item->unit_price), self::money($item->unit_price * $item->quantity)));

            if ($labels = $item->labels()) {
                $add($t->row('  '.implode(', ', $labels)));
            }
        }

        $out[] = ['text' => $t->rule()];
        $add($t->row(__('pos.print.subtotal'), self::money($order->subtotal)));

        if ($order->discount_amount) {
            $add($t->row(__('pos.print.discount'), '-'.self::money($order->discount_amount)));
        }

        if ($order->points_discount) {
            $add($t->row(__('pos.print.points', ['points' => $order->points_redeemed]), '-'.self::money($order->points_discount)));
        }

        if ($order->service_amount) {
            $add($t->row($s->get('charges.service_label').' '.self::rate($order->service_rate).'%', self::money($order->service_amount)));
        }

        if ($order->tax_amount) {
            $add($t->row($s->get('charges.tax_label').' '.self::rate($order->tax_rate).'%', self::money($order->tax_amount)));
        }

        $add($t->row(__('pos.print.total'), 'Rp '.self::money($order->total)), 'b');

        foreach ($order->payments as $payment) {
            $add($t->row(trim($payment->method_name.' '.$payment->bank_name), self::money($payment->received)));

            if ($payment->method_kind === PaymentKind::Cash) {
                $add($t->row(__('pos.print.change'), self::money($payment->change)), 'b');
            }
        }

        $out[] = ['text' => $t->rule()];

        if (filled($s->get('store.footer'))) {
            $add($t->center((string) $s->get('store.footer')));
        }

        return ['cols' => $t->cols, 'lines' => $out];
    }

    /** Laporan shift 57 mm (penjualan per metode & bank, kas, selisih, setoran, open bill, EDC, tanda tangan). */
    public function shiftReport(Shift $shift): array
    {
        $shift->loadMissing(['opener', 'closer', 'cashMovements.approver', 'carriedOpenBills', 'previousShift']);
        $t = new TextLayout($this->cols());
        $out = [];
        $add = function (array $lines, ?string $style = null) use (&$out) {
            foreach ($lines as $text) {
                $out[] = $style ? ['text' => $text, 'style' => $style] : ['text' => $text];
            }
        };
        $rule = function () use (&$out, $t) {
            $out[] = ['text' => $t->rule()];
        };
        $sum = $shift->summary ?? $shift->summarize();
        $sign = fn (int $n) => ($n > 0 ? '+' : ($n < 0 ? '-' : '')).self::money(abs($n));
        $cashier = $shift->opener?->name ?? '-';
        $settlement = $shift->is_day_close ? EdcSettlement::with('lines')->where('shift_id', $shift->id)->first() : null;

        $add($t->center(__($shift->is_day_close ? 'pos.print.shift_report_day' : 'pos.print.shift_report')), 'b');
        $add($t->center((string) $this->settings->get('store.name')), 'big-c');
        $add($t->row(__('pos.print.shift_line', ['name' => $shift->name, 'cashier' => $cashier]), $shift->opened_at->format('d/m/Y')));
        $add($t->row(__('pos.print.shift_time', ['open' => $shift->opened_at->format('H:i'), 'close' => $shift->closed_at?->format('H:i') ?? '-'])));
        $rule();

        $add($t->row(__('pos.print.sales'), __('pos.print.trx', ['count' => $sum['count']])), 'b');

        foreach ($sum['by_method'] as $method => $amount) {
            $banks = array_filter($sum['by_bank'], fn ($label) => str_starts_with($label, $method.' '), ARRAY_FILTER_USE_KEY);

            if ($banks) {
                foreach ($banks as $label => $value) {
                    $add($t->row($label, self::money($value)));
                }
            } else {
                $add($t->row($method, self::money($amount)));
            }
        }

        if ($sum['discount']) {
            $add($t->row(__('pos.print.discount_given'), self::money($sum['discount'])));
        }

        foreach ($sum['refunds_by_method'] ?? [] as $method => $amount) {
            $add($t->row(__('pos.print.refund_method', ['method' => $method]), '-'.self::money($amount)));
        }

        $add($t->row(__(($sum['refunds'] ?? 0) ? 'pos.print.total_net' : 'pos.print.total'), self::money($sum['net'] ?? $sum['total'])), 'b');
        $rule();

        $add($t->row(__('pos.print.drawer')), 'b');
        $add($t->row(__('pos.print.float'), self::money($shift->opening_float)));

        if ($shift->handover_expected !== null && $shift->handover_expected !== $shift->opening_float) {
            $add($t->row(__('pos.print.handover', ['amount' => self::money($shift->handover_expected), 'note' => (string) $shift->handover_note])));
        }

        $add($t->row(__('pos.print.cash_sales'), '+'.self::money($sum['cash_sales'])));

        if ($sum['cash_refunds']) {
            $add($t->row(__('pos.print.cash_refunds'), '-'.self::money($sum['cash_refunds'])));
        }

        foreach ($shift->cashMovements->sortBy('id') as $c) {
            $in = $c->type === CashMovementType::In;
            $add($t->row(__($in ? 'pos.print.cash_in' : 'pos.print.cash_out', ['reason' => $c->reason_label]), ($in ? '+' : '-').self::money($c->amount)));

            if ($c->approver) {
                $add($t->row(__('pos.print.approved', ['name' => $c->approver->name])));
            }
        }

        $add($t->row(__('pos.print.expected'), self::money((int) $shift->expected_cash)));
        $add($t->row(__('pos.print.counted'), self::money((int) $shift->counted_cash)), 'b');
        $add($t->row(__('pos.print.variance'), $sign((int) $shift->variance)), 'b');

        if ($shift->variance) {
            $add($t->row('  '.$shift->variance_note));
        }

        if ($shift->variance_approved_by) {
            $add($t->row(__('pos.print.approved', ['name' => User::find($shift->variance_approved_by)?->name])));
        }

        $rule();
        $add($t->row(__('pos.print.deposit'), self::money((int) $shift->deposit_amount)), 'b');
        $add($t->row(__('pos.print.float_left'), self::money((int) $shift->float_left)));

        if ($shift->carried_open_bills_count) {
            $rule();
            $add($t->row(__('pos.print.open_bills'), __('pos.print.open_bills_count', ['count' => $shift->carried_open_bills_count])), 'b');

            foreach ($shift->carriedOpenBills as $bill) {
                $add($t->row('  '.$bill->code.' '.$bill->customer_name, self::money($bill->pivot->total)));
            }

            $add($t->row(__('pos.print.open_bills_unpaid'), self::money($shift->carried_open_bills_total)), 'b');

            if ($shift->open_bills_approved_by) {
                $add($t->row(__('pos.print.open_bills_day', ['name' => User::find($shift->open_bills_approved_by)?->name])));
            }
        }

        if ($settlement) {
            $rule();
            $add($t->row(__('pos.print.edc')), 'b');

            foreach ($settlement->lines as $line) {
                $add($t->row($line->label));
                $add($t->row(__('pos.print.edc_line', ['pos' => self::money($line->pos_amount)]), self::money($line->edc_amount)));

                if ($line->difference) {
                    $add($t->row(__('pos.print.edc_diff'), $sign($line->difference)), 'b');
                }
            }

            if ($settlement->note) {
                $add($t->row('  '.$settlement->note));
            }
        }

        $rule();
        $add($t->row(__('pos.print.sign_cashier'), __('pos.print.sign_owner')));
        $out[] = ['text' => ''];
        $out[] = ['text' => ''];
        $add($t->row('('.$cashier.')', '(__________)'));

        return ['cols' => $t->cols, 'lines' => $out];
    }

    public function testPage(): array
    {
        $t = new TextLayout($this->cols());
        $lines = [];

        foreach ($t->center(__('pos.print.test')) as $text) {
            $lines[] = ['text' => $text, 'style' => 'b'];
        }

        foreach ($t->center((string) $this->settings->get('store.name')) as $text) {
            $lines[] = ['text' => $text];
        }

        foreach ($t->row(now()->format('d/m/Y'), now()->format('H:i')) as $text) {
            $lines[] = ['text' => $text];
        }

        $lines[] = ['text' => $t->rule()];
        $lines[] = ['text' => substr(str_repeat('1234567890', 5), 0, $t->cols)];

        foreach ($t->center(__('pos.print.test_big'), intdiv($t->cols, 2)) as $text) {
            $lines[] = ['text' => $text, 'style' => 'big'];
        }

        $lines[] = ['text' => $t->rule()];

        foreach ($t->center(__('pos.print.test_ready')) as $text) {
            $lines[] = ['text' => $text];
        }

        return ['cols' => $t->cols, 'lines' => $lines];
    }

    /** Kiriman ke printer: byte ESC/POS (base64) + pratinjau baris (untuk dialog cetak / RawBT). */
    public function job(array $docs): ?array
    {
        $docs = array_values(array_filter($docs));

        return $docs === [] ? null : ['escpos' => base64_encode(EscPos::encode($docs)), 'docs' => $docs, 'cols' => $docs[0]['cols']];
    }

    private function where(Order $order): string
    {
        return $order->table_number ? $order->order_type_name.' - '.__('pos.print.table', ['table' => $order->table_number]) : mb_strtoupper($order->order_type_name);
    }

    public static function money(int $amount): string
    {
        return number_format($amount, 0, ',', '.');
    }

    private static function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',');
    }
}
