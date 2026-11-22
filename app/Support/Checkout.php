<?php

namespace App\Support;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\Device;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSequence;
use App\Models\OrderType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Simpan (open bill, bayar nanti) & bayar pesanan dari kasir. Semua divalidasi & dihitung ulang
 * di server dalam satu transaksi: shift berjalan, menu & varian masih tersedia, persetujuan diskon,
 * metode & bank, uang diterima. Nomor antrean & kode diambil sekali (saat pertama disimpan/dibayar)
 * dan tetap; penjualan dihitung saat dibayar (shift & tanggal bayar).
 */
class Checkout
{
    /** Batas wajar uang diterima (9 digit, sama dengan keypad mockup). */
    public const MAX_RECEIVED = 999_999_999;

    public function __construct(
        private readonly OrderDraft $draft,
        private readonly ShiftService $shifts,
        private readonly Settings $settings,
        private readonly Customers $customers,
    ) {}

    /**
     * Simpan pesanan (bayar nanti). Pesanan tersimpan yang dibuka lagi diperbarui (kode tetap).
     *
     * @throws ValidationException
     */
    public function hold(User $cashier, Device $device, array $state, string $customerName): CheckoutResult
    {
        // Tukar poin hanya saat bayar (saldo dipotong saat itu); open bill disimpan tanpa potongan poin.
        $state['points'] = 0;
        $prepared = $this->prepare($cashier, $state, $customerName);

        return DB::transaction(function () use ($cashier, $device, $state, $prepared) {
            $shift = $this->lockShift($device);
            [$order, $ticket, $isAddition] = $this->saveOrder($state, $prepared, $cashier, $device, $shift, OrderStatus::Open);

            return new CheckoutResult($order, $ticket, $isAddition);
        });
    }

    /**
     * Bayar pesanan baru atau pesanan tersimpan yang sedang dibuka.
     *
     * @throws ValidationException
     */
    public function pay(User $cashier, Device $device, array $state, string $customerName, int $methodId, ?int $bankId, int $received, bool $printReceipt): CheckoutResult
    {
        $prepared = $this->prepare($cashier, $state, $customerName);
        $total = $prepared['priced']['totals']->total;

        $method = PaymentMethod::active()->find($methodId);

        if (! $method) {
            throw ValidationException::withMessages(['method' => __('pos.pay.errors.method')]);
        }

        $bank = null;

        if ($method->requires_bank) {
            $bank = Bank::active()->find($bankId);

            if (! $bank) {
                throw ValidationException::withMessages(['bank' => __('pos.pay.errors.bank')]);
            }
        }

        // EDC/QRIS: nominal = total. Tunai: uang diterima ≥ total, kembalian dihitung di sini.
        $received = $method->kind === PaymentKind::Cash ? $received : $total;

        if ($received < $total || $received > self::MAX_RECEIVED) {
            throw ValidationException::withMessages(['received' => __('pos.pay.errors.received')]);
        }

        return DB::transaction(function () use ($cashier, $device, $state, $prepared, $method, $bank, $received, $total, $printReceipt) {
            $shift = $this->lockShift($device);
            [$order, $ticket, $isAddition] = $this->saveOrder($state, $prepared, $cashier, $device, $shift, OrderStatus::Paid, $printReceipt);
            $now = CarbonImmutable::now();

            Payment::create([
                'order_id' => $order->id,
                'payment_method_id' => $method->id,
                'method_name' => $method->name,
                'method_kind' => $method->kind,
                'bank_id' => $bank?->id,
                'bank_name' => $bank?->name,
                'amount' => $total,
                'received' => $received,
                'change' => $received - $total,
                'shift_id' => $shift->id,
                'business_date' => BusinessDate::today($now),
                'user_id' => $cashier->id,
                'paid_at' => $now,
            ]);
            $this->customers->redeem($order, $cashier, $device);
            $this->customers->recordVisit($order);

            return new CheckoutResult($order, $ticket, $isAddition);
        });
    }

    /**
     * Validasi & harga (di luar transaksi; tidak menulis apa pun).
     *
     * @return array{name: ?string, customer: ?Customer, type: OrderType, lines: list<array>, discount: array, priced: array, discountType: ?DiscountType, approver: ?User}
     *
     * @throws ValidationException
     */
    private function prepare(User $cashier, array $state, string $customerName): array
    {
        $customerName = mb_substr(trim($customerName), 0, 24);

        if ($customerName === '' && $this->settings->get('policy.require_customer_name')) {
            throw ValidationException::withMessages(['customerName' => __('pos.pay.errors.name')]);
        }

        if (! $cashier->hasPermission(Permission::Pos)) {
            throw ValidationException::withMessages(['pay' => __('pos.shift.errors.not_allowed')]);
        }

        // Validasi ulang setiap baris (menu bisa saja baru dinyatakan habis/nonaktif). Baris open bill yang sudah
        // dipesan & tercetak dibandingkan dengan salinan di pesanan (dari database, bukan dari draf): yang sudah
        // dibuat tetap bisa dibayar dengan harga saat dipesan; ketersediaan hanya dicek untuk tambahan.
        $storedItems = $this->storedItems($state);
        $lines = [];

        foreach ($state['lines'] ?? [] as $line) {
            $printed = max(0, (int) ($line['printed'] ?? 0));
            $stored = $storedItems[OrderDraft::signature((int) $line['item_id'], (array) $line['picks'], (string) $line['note'])] ?? null;

            try {
                $clean = $stored && (int) $line['qty'] <= $printed
                    ? $this->draft->storedLine($line)
                    : $this->draft->line((int) $line['item_id'], (array) $line['picks'], (int) $line['qty'], (string) $line['note']);
            } catch (ValidationException) {
                $name = MenuItem::withTrashed()->find($line['item_id'])?->name;

                throw ValidationException::withMessages(['pay' => __('pos.pay.errors.item_unavailable', ['item' => $name])]);
            }

            $lines[] = $clean + ['printed' => min($clean['qty'], $printed)] + ($stored ? ['stored' => $stored] : []);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['pay' => __('pos.pay.errors.empty')]);
        }

        $type = OrderType::active()->find($state['type_id'] ?? null);

        if (! $type) {
            throw ValidationException::withMessages(['pay' => __('pos.pay.errors.type')]);
        }

        $discount = $state['discount'] ?? OrderDraft::blank(null)['discount'];
        $points = max(0, (int) ($state['points'] ?? 0));
        $normalized = ['type_id' => $type->id, 'table' => $type->needs_table ? (string) ($state['table'] ?? '') : '', 'lines' => $lines, 'discount' => $discount, 'points' => $points];

        $priced = $this->draft->price($normalized);
        $approver = $discount['approved_by'] ? User::find($discount['approved_by']) : null;

        // Diskon di atas batas harus disetujui karyawan yang (masih) berhak menyetujui.
        $shrunk = ($discount['type'] ?? null) === DiscountType::Amount->value && $priced['totals']->subtotal < (int) ($discount['approved_subtotal'] ?? 0);

        if ($priced['needs_approval'] && (! $approver?->hasPermission(Permission::Approve) || $shrunk)) {
            throw ValidationException::withMessages(['pay' => __('pos.pay.errors.discount_approval')]);
        }

        // Pelanggan terdaftar (bila database pelanggan aktif dan datanya belum dihapus).
        $customer = $this->customers->enabled() && ! empty($state['customer_id']) ? Customer::find($state['customer_id']) : null;
        if ($points > 0) {
            $this->customers->ensureRedeemable($customer, $points, $priced['totals'], $this->draft->pointsValue($points));
        }

        return [
            'name' => $customerName !== '' ? $customerName : null,
            'customer' => $customer,
            'points' => $points,
            'type' => $type,
            'table' => $normalized['table'],
            'lines' => $lines,
            'discount' => $discount,
            'priced' => $priced,
            'discountType' => DiscountType::tryFrom((string) $discount['type']),
            'approver' => $approver,
        ];
    }

    /** @throws ValidationException */
    private function lockShift(Device $device): Shift
    {
        Device::whereKey($device->id)->lockForUpdate()->first();
        $shift = $this->shifts->running($device);

        if (! $shift) {
            throw ValidationException::withMessages(['pay' => __('pos.pay.errors.no_shift')]);
        }

        return $shift;
    }

    /**
     * Buat pesanan baru atau perbarui pesanan tersimpan (dikunci & dicek versinya: dua tablet tidak
     * bisa saling menimpa). Item diganti dengan salinan terbaru; setelah simpan/bayar semua tercetak.
     *
     * @return array{0: Order, 1: list<array>, 2: bool}
     *
     * @throws ValidationException
     */
    private function saveOrder(array $state, array $p, User $cashier, Device $device, Shift $shift, OrderStatus $status, bool $printReceipt = true): array
    {
        $now = CarbonImmutable::now();
        $order = null;

        if (! empty($state['order_id'])) {
            $order = Order::whereKey($state['order_id'])->lockForUpdate()->first();

            if (! $order || $order->status !== OrderStatus::Open) {
                throw ValidationException::withMessages(['pay' => __('pos.held.errors.not_open')]);
            }

            if ((string) ($state['version'] ?? '') !== self::version($order)) {
                throw ValidationException::withMessages(['pay' => __('pos.held.errors.changed')]);
            }

            // Lewat hari tanpa persetujuan (mis. shift ditutup tanpa "tutup hari") tidak bisa disimpan/dibayar.
            if ($order->needsDayApproval()) {
                throw ValidationException::withMessages(['pay' => __('pos.held.errors.day_approval')]);
            }
        }

        $isAddition = $order !== null;
        $totals = $p['priced']['totals'];

        if ($order) {
            $this->ensurePrintedItemsVoided($order, $p['lines'], $state['voids'] ?? []);
        }

        if (! $order) {
            $day = BusinessDate::today($now);
            $queue = OrderSequence::next($day);
            $order = new Order;
            $order->forceFill([
                'code' => Order::makeCode($day, $queue),
                'business_date' => $day,
                'queue_number' => $queue,
                'device_id' => $device->id,
                'created_by' => $cashier->id,
                'opened_shift_id' => $shift->id,
            ]);
        }

        $order->fill([
            'customer_id' => $p['customer']?->id,
            'customer_name' => $p['name'],
            'order_type_id' => $p['type']->id,
            'order_type_name' => $p['type']->name,
            'table_number' => $p['table'] !== '' ? $p['table'] : null,
            'discount_type' => $totals->discount > 0 ? $p['discountType'] : null,
            'discount_value' => $totals->discount > 0 ? (int) $p['discount']['value'] : 0,
            'receipt_printed' => $printReceipt,
        ]);
        $order->forceFill([
            'status' => $status,
            'subtotal' => $totals->subtotal,
            'discount_amount' => $totals->discount,
            'discount_approved_by' => $totals->discount > 0 ? $p['approver']?->id : null,
            'service_rate' => $totals->serviceRate,
            'service_amount' => $totals->service,
            'tax_rate' => $totals->taxRate,
            'tax_amount' => $totals->tax,
            'total' => $totals->total,
            'points_redeemed' => $totals->points > 0 ? $p['points'] : 0,
            'points_discount' => $totals->points,
        ]);

        if ($status === OrderStatus::Paid) {
            $order->forceFill(['paid_at' => $now, 'paid_by' => $cashier->id, 'paid_shift_id' => $shift->id, 'paid_business_date' => BusinessDate::today($now)]);
        }

        $order->save();
        $ticket = $this->saveItems($order, $p['lines']);

        // Draf baru hanya bisa disimpan/dibayar sekali (ketuk ganda / dua permintaan bersamaan → satu pesanan).
        if (! $isAddition && ! empty($state['draft_id']) && ! Cache::add('pos-draft-used:'.$state['draft_id'], $order->id, now()->addDays(2))) {
            throw ValidationException::withMessages(['pay' => __('pos.pay.errors.already_processed')]);
        }

        foreach ($state['voids'] ?? [] as $void) {
            AuditLog::record('order_item.voided', $order, null, [
                'item' => $void['name'], 'qty' => $void['qty'], 'reason' => $void['reason'], 'note' => $void['note'],
            ], approvedBy: User::find($void['approved_by']), user: $cashier, device: $device);
        }

        return [$order, $ticket, $isAddition];
    }

    /**
     * Item yang sudah tercetak di tiket (sudah dibuat barista) hanya boleh dikurangi/diubah bila ada
     * catatan void dengan alasan & PIN penyetuju (dibuat lewat Kasir::confirmCorrection).
     *
     * @throws ValidationException
     */
    private function ensurePrintedItemsVoided(Order $order, array $lines, array $voids): void
    {
        $printed = [];

        foreach ($order->items()->with('modifiers')->get() as $item) {
            if ($item->menu_item_id && $item->printed_quantity > 0) {
                $picks = $item->modifiers->filter(fn ($m) => $m->modifier_group_id && $m->modifier_option_id)->mapWithKeys(fn ($m) => [$m->modifier_group_id => $m->modifier_option_id])->all();
                $sig = OrderDraft::signature($item->menu_item_id, $picks, (string) $item->note);
                $printed[$sig] = ($printed[$sig] ?? 0) + $item->printed_quantity;
            }
        }

        $kept = [];

        foreach ($lines as $line) {
            $sig = OrderDraft::signature($line['item_id'], $line['picks'], $line['note']);
            $kept[$sig] = ($kept[$sig] ?? 0) + $line['qty'];
        }

        $voided = [];

        foreach ($voids as $void) {
            $approver = User::find($void['approved_by'] ?? null);

            if ($approver?->hasPermission(Permission::Approve)) {
                $voided[$void['signature']] = ($voided[$void['signature']] ?? 0) + (int) $void['qty'];
            }
        }

        foreach ($printed as $sig => $qty) {
            if ($qty - ($kept[$sig] ?? 0) > ($voided[$sig] ?? 0)) {
                throw ValidationException::withMessages(['pay' => __('pos.corrections.errors.printed_removed')]);
            }
        }
    }

    /**
     * Versi pesanan tersimpan untuk deteksi perubahan dari tablet lain: waktu ubah (presisi detik di DB),
     * total, dan sidik isi item (menu, varian, jumlah, catatan, tercetak) — ganti varian berharga sama tetap terdeteksi.
     */
    public static function version(Order $order): string
    {
        $items = $order->items()->with('modifiers')->orderBy('id')->get()->map(fn ($i) => [
            $i->menu_item_id, $i->quantity, $i->printed_quantity, (string) $i->note, $i->modifiers->pluck('modifier_option_id')->sort()->values()->all(),
        ]);

        return sha1($order->updated_at?->format('Y-m-d H:i:s.u').'#'.$order->total.'#'.$items->toJson());
    }

    /**
     * Item pesanan dengan salinan nama, kategori, stasiun, harga & varian (laporan tidak berubah bila menu diubah).
     *
     * @return list<array{name: string, labels: list<string>, qty: int, station: ?string}> item belum tercetak (untuk tiket)
     */
    private function saveItems(Order $order, array $lines): array
    {
        $order->items()->delete();
        $items = MenuItem::with(['category.station', 'modifierGroups.options'])->findMany(array_column($lines, 'item_id'))->keyBy('id');
        $ticket = [];

        foreach ($lines as $sort => $line) {
            if (isset($line['stored'])) {
                $ticket = array_merge($ticket, $this->saveStoredItem($order, $line, $sort));

                continue;
            }

            $item = $items[$line['item_id']];
            [$unit, $labels] = $this->draft->unitPriceAndLabels($item, $line['picks']);

            if ($line['qty'] > $line['printed']) {
                $ticket[] = ['name' => $item->name, 'labels' => array_merge($labels, $line['note'] !== '' ? ['"'.$line['note'].'"'] : []), 'qty' => $line['qty'] - $line['printed'], 'station' => $item->category->station->name];
            }

            $orderItem = $order->items()->create([
                'menu_item_id' => $item->id,
                'name' => $item->name,
                'category_name' => $item->category->name,
                'station_name' => $item->category->station->name,
                'base_price' => $item->price,
                'unit_price' => $unit,
                'quantity' => $line['qty'],
                'note' => $line['note'] !== '' ? $line['note'] : null,
                // Tiket barista dicetak saat disimpan/dibayar (tahap cetak menyusul).
                'printed_quantity' => $line['qty'],
                'sort' => $sort,
            ]);

            foreach ($item->modifierGroups as $group) {
                $option = $group->options->firstWhere('id', $line['picks'][$group->id] ?? null);

                if ($option) {
                    $orderItem->modifiers()->create([
                        'modifier_group_id' => $group->id,
                        'modifier_option_id' => $option->id,
                        'group_name' => $group->name,
                        'option_name' => $option->name,
                        'price_delta' => $option->price_delta,
                        'is_default' => $option->id === $this->draft->defaultOption($group)?->id,
                        'print_always' => $group->print_always,
                    ]);
                }
            }
        }

        return $ticket;
    }

    /**
     * Salinan item open bill yang sudah dipesan, per sidik (menu + varian + catatan), dari database.
     *
     * @return array<string, array{unit: int, name: string, labels: list<string>, item: array, modifiers: list<array>}>
     */
    private function storedItems(array $state): array
    {
        if (empty($state['order_id'])) {
            return [];
        }

        $stored = [];

        foreach (OrderItem::with('modifiers')->where('order_id', $state['order_id'])->where('printed_quantity', '>', 0)->whereNotNull('menu_item_id')->get() as $item) {
            $picks = $item->modifiers->filter(fn ($m) => $m->modifier_group_id && $m->modifier_option_id)->mapWithKeys(fn ($m) => [$m->modifier_group_id => $m->modifier_option_id])->all();
            $stored[OrderDraft::signature($item->menu_item_id, $picks, (string) $item->note)] ??= [
                'unit' => $item->unit_price,
                'name' => $item->name,
                'labels' => $item->modifiers->filter(fn ($m) => ! $m->is_default || $m->print_always)->pluck('option_name')->values()->all(),
                'item' => $item->only(['name', 'category_name', 'station_name', 'base_price', 'unit_price']),
                'modifiers' => $item->modifiers->map(fn ($m) => $m->only(['modifier_group_id', 'modifier_option_id', 'group_name', 'option_name', 'price_delta', 'is_default', 'print_always']))->values()->all(),
            ];
        }

        return $stored;
    }

    /**
     * Simpan ulang baris yang sudah dipesan dari salinannya (harga & varian saat dipesan); tambahan jumlah
     * masuk tiket TAMBAHAN dengan nama & varian yang sama.
     *
     * @return list<array{name: string, labels: list<string>, qty: int, station: ?string}>
     */
    private function saveStoredItem(Order $order, array $line, int $sort): array
    {
        $s = $line['stored'];
        $orderItem = $order->items()->create($s['item'] + [
            'menu_item_id' => $line['item_id'],
            'quantity' => $line['qty'],
            'note' => $line['note'] !== '' ? $line['note'] : null,
            'printed_quantity' => $line['qty'],
            'sort' => $sort,
        ]);

        foreach ($s['modifiers'] as $modifier) {
            $orderItem->modifiers()->create($modifier);
        }

        return $line['qty'] > $line['printed']
            ? [['name' => $s['name'], 'labels' => array_merge($s['labels'], $line['note'] !== '' ? ['"'.$line['note'].'"'] : []), 'qty' => $line['qty'] - $line['printed'], 'station' => $s['item']['station_name']]]
            : [];
    }

    /**
     * Status draf dari pesanan tersimpan (untuk dibuka lagi di kasir).
     *
     * @return array{state: array, name: string}
     */
    public function toDraft(Order $order): array
    {
        $order->load('items.modifiers');
        $lines = $order->items->filter(fn ($i) => $i->menu_item_id !== null)->map(fn ($i) => [
            'item_id' => $i->menu_item_id,
            'picks' => $i->modifiers->filter(fn ($m) => $m->modifier_group_id && $m->modifier_option_id)->mapWithKeys(fn ($m) => [$m->modifier_group_id => $m->modifier_option_id])->sortKeys()->all(),
            'qty' => $i->quantity,
            'note' => (string) $i->note,
            'printed' => $i->printed_quantity,
            // Untuk tampilan di kasir (harga & varian saat dipesan); Checkout membaca ulang dari database.
            'stored' => ['unit' => $i->unit_price, 'name' => $i->name, 'labels' => $i->modifiers->filter(fn ($m) => ! $m->is_default || $m->print_always)->pluck('option_name')->values()->all()],
        ])->values()->all();

        return [
            'state' => [
                'order_id' => $order->id,
                'version' => self::version($order),
                'type_id' => $order->order_type_id,
                'table' => (string) $order->table_number,
                'lines' => $lines,
                'discount' => ['type' => $order->discount_type?->value, 'value' => $order->discount_value, 'approved_by' => $order->discount_approved_by, 'approved_subtotal' => $order->subtotal],
                'voids' => [],
                'customer_id' => $order->customer_id,
            ],
            'name' => (string) $order->customer_name,
        ];
    }
}
