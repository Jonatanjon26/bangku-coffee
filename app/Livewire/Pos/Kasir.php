<?php

namespace App\Livewire\Pos;

use App\Enums\CashMovementType;
use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\ReasonType;
use App\Models\AuditLog;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\DiscountPreset;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderSequence;
use App\Models\OrderType;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\Approval;
use App\Support\BusinessDate;
use App\Support\Checkout;
use App\Support\Customers;
use App\Support\OrderCorrections;
use App\Support\OrderDraft;
use App\Support\PosLogin;
use App\Support\PosLoginException;
use App\Support\PosSession;
use App\Support\Printing\Documents;
use App\Support\Settings;
use App\Support\ShiftService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Layar kasir (docs/mockup/kasir.html): buka shift wajib, menu & pesanan.
 * Pesanan yang disusun hanya berisi id & jumlah (OrderDraft); harga & total dihitung di server.
 * Draf disimpan di sesi tablet supaya tetap ada setelah kunci/ganti kasir (seperti mockup).
 * Rute & setiap aksi Livewire melewati EnsurePosDevice + EnsurePosCashier (middleware persisten);
 * kasir & tablet juga dibaca ulang di setiap aksi.
 */
#[Layout('components.layouts.pos')]
class Kasir extends Component
{
    private const DRAFT = 'pos.draft';

    public const NAME_MAX = 24;

    /** Pesanan yang sedang disusun — hanya diubah lewat aksi di bawah (dicek di server). */
    #[Locked]
    public array $order = [];

    /** Diskon yang menunggu PIN persetujuan. */
    #[Locked]
    public ?array $pendingDiscount = null;

    /** Koreksi yang menunggu alasan (+ PIN penyetuju): void item tercetak, refund, batal open bill. */
    #[Locked]
    public ?array $pendingCorrection = null;

    /** Open bill lewat hari yang menunggu PIN persetujuan sebelum dibuka. */
    #[Locked]
    public ?int $pendingHeld = null;

    /** Transaksi yang dibuka di Riwayat. */
    #[Locked]
    public ?int $historyId = null;

    public string $customerName = '';

    public function mount(): void
    {
        $draft = session(self::DRAFT);
        $this->order = is_array($draft) && isset($draft['lines']) ? $draft + OrderDraft::blank(null) : OrderDraft::blank(OrderType::active()->value('id'));
        $this->customerName = (string) ($draft['name'] ?? '');
        unset($this->order['name']);
    }

    /* ---------------------------------------------------------------- pesanan */

    public function addLine(OrderDraft $draft, int $itemId, array $picks = [], int $qty = 1, string $note = ''): void
    {
        $this->context();
        $line = $draft->line($itemId, $picks, $qty, $note);
        $this->order = $draft->add($this->order, $line);
        $this->persist();

        $name = MenuItem::find($itemId)->name;
        $this->dispatch('toast', message: __('pos.order.added', ['item' => ($qty > 1 ? $qty.'× ' : '').$name]));
    }

    public function updateLine(OrderDraft $draft, int $index, array $picks = [], int $qty = 1, string $note = ''): void
    {
        $this->context();
        $current = $this->order['lines'][$index] ?? null;

        if (! $current) {
            return;
        }

        $line = $draft->line($current['item_id'], $picks, $qty, $note);
        $printed = (int) ($current['printed'] ?? 0);
        // Varian/catatan item yang sudah tercetak berubah, atau jumlahnya dikurangi → alasan + PIN penyetuju;
        // item baru dicetak ulang di tiket berikutnya.
        $changed = $line['picks'] != $current['picks'] || $line['note'] !== $current['note'];

        if ($printed > 0 && ($changed || $line['qty'] < $printed)) {
            $this->requestItemVoid($index, $line['qty'], $changed ? $line : null);

            return;
        }

        $this->order['lines'][$index] = $line + ['printed' => min($printed, $line['qty'])];
        $this->persist();
    }

    public function changeQty(int $index, int $delta): void
    {
        $this->context();

        if (! isset($this->order['lines'][$index]) || ! in_array($delta, [-1, 1], true)) {
            return;
        }

        $qty = $this->order['lines'][$index]['qty'] + $delta;

        // Mengurangi item yang sudah tercetak di tiket → alasan + PIN penyetuju.
        if ($qty < (int) ($this->order['lines'][$index]['printed'] ?? 0)) {
            $this->requestItemVoid($index, $qty, null);

            return;
        }

        if ($qty <= 0) {
            array_splice($this->order['lines'], $index, 1);
        } else {
            $this->order['lines'][$index]['qty'] = min(OrderDraft::MAX_QTY, $qty);
            $this->order['lines'][$index]['printed'] = min((int) ($this->order['lines'][$index]['printed'] ?? 0), $this->order['lines'][$index]['qty']);
        }

        $this->persist();
    }

    public function setType(int $typeId): void
    {
        $this->context();
        $type = OrderType::active()->find($typeId);

        if (! $type) {
            return;
        }

        $this->order['type_id'] = $type->id;

        if (! $type->needs_table) {
            $this->order['table'] = '';
        }

        $this->persist();
    }

    public function setTable(string $table): void
    {
        $this->context();
        $type = OrderType::find($this->order['type_id']);

        if (! $type?->needs_table || ! preg_match('/^\d{0,'.OrderDraft::TABLE_MAX.'}$/', $table)) {
            throw ValidationException::withMessages(['table' => __('pos.order.errors.table')]);
        }

        $this->order['table'] = ltrim($table, '0');
        $this->persist();
    }

    public function updatedCustomerName(): void
    {
        $this->customerName = mb_substr(trim($this->customerName), 0, self::NAME_MAX);

        // Nama diubah dari nama pelanggan terdaftar → lepas (seperti mockup).
        if ($this->order['customer_id'] && Customer::find($this->order['customer_id'])?->name !== $this->customerName) {
            $this->order['customer_id'] = null;
            $this->order['points'] = 0;
        }

        $this->persist();
    }

    /* ---------------------------------------------------------------- ganti PIN sendiri */

    public function changePin(PosLogin $login, string $current, string $new, string $confirm): void
    {
        [$user, $device] = $this->context();

        try {
            $login->changePin($user, $device, $current, $new, $confirm);
        } catch (ValidationException|PosLoginException $e) {
            $this->dispatch('pin-change-failed',
                message: $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage(),
                restart: $e instanceof PosLoginException);

            return;
        }

        $this->dispatch('pin-changed');
        $this->dispatch('toast', message: __('pos.pin_change.done'));
    }

    /* ---------------------------------------------------------------- pelanggan */

    /** Saran pelanggan untuk kolom nama (nama atau no. HP). */
    public function searchCustomers(Customers $customers, string $query): array
    {
        [$user] = $this->context();

        // Dibatasi per kasir: data pelanggan tidak bisa dikuras dengan mencoba semua kombinasi huruf/angka.
        if (! $customers->allowLookup($user)) {
            return [];
        }

        return $customers->search(mb_substr($query, 0, 30))->map(fn (Customer $c) => $customers->suggestion($c))->all();
    }

    public function linkCustomer(Customers $customers, int $customerId): void
    {
        [$user] = $this->context();

        if (! $customers->allowLookup($user)) {
            $this->dispatch('toast', message: __('pos.customers.errors.too_many'));

            return;
        }

        $customer = $customers->enabled() ? Customer::find($customerId) : null;

        if (! $customer) {
            return;
        }

        $this->order['customer_id'] = $customer->id;
        $this->order['points'] = 0;
        $this->customerName = mb_substr($customer->name, 0, self::NAME_MAX);
        $this->persist();
    }

    public function unlinkCustomer(): void
    {
        $this->context();
        $this->order['customer_id'] = null;
        $this->order['points'] = 0;
        $this->persist();
    }

    /** Tukar poin pelanggan terhubung jadi potongan (0 = batal). Dicek ulang & dipotong di server saat bayar. */
    public function redeemPoints(Customers $customers, OrderDraft $draft, int $points): void
    {
        $this->context();
        $points = max(0, $points);

        if ($points > 0) {
            $state = ['points' => $points] + $this->order;

            try {
                $customers->ensureRedeemable(Customer::find($this->order['customer_id']), $points, $draft->price($state)['totals'], $draft->pointsValue($points));
            } catch (ValidationException $e) {
                $this->dispatch('toast', message: collect($e->errors())->flatten()->first());

                return;
            }
        }

        $this->order['points'] = $points;
        $this->persist();
    }

    /** Simpan pelanggan baru dari kolom nama; no. HP yang sudah terdaftar memakai pelanggan lama. */
    public function registerCustomer(Customers $customers, string $name, string $phone = '', string $notes = '', bool $consent = false): void
    {
        [$user] = $this->context();

        // No. HP yang sudah terdaftar menampilkan pelanggannya: ikut batas cari pelanggan.
        if (! $customers->allowLookup($user)) {
            $this->dispatch('customer-failed', message: __('pos.customers.errors.too_many'));

            return;
        }

        try {
            [$customer, $existing] = $customers->register(['name' => $name, 'phone' => $phone, 'notes' => $notes, 'contact_consent' => $consent], $user);
        } catch (ValidationException $e) {
            $this->dispatch('customer-failed', message: collect($e->errors())->flatten()->first());

            return;
        }

        $this->order['customer_id'] = $customer->id;
        $this->customerName = mb_substr($customer->name, 0, self::NAME_MAX);
        $this->persist();
        $this->dispatch('customer-saved');
        $this->dispatch('toast', message: __($existing ? 'pos.customers.existing' : 'pos.customers.saved', ['name' => $customer->name]));
    }

    /** Kosongkan pesanan baru, atau tutup pesanan tersimpan (tetap tersimpan, bisa dibuka lagi). */
    public function clearOrder(): void
    {
        $this->context();
        $this->resetDraft();
    }

    /* ---------------------------------------------------------------- diskon */

    /** Terapkan diskon; di atas batas pengaturan → minta PIN persetujuan dulu. */
    public function applyDiscount(OrderDraft $draft, ?string $type, int $value = 0): void
    {
        $this->context();
        $discountType = DiscountType::tryFrom((string) $type);

        if (($type !== null && $type !== '' && ! $discountType) || $value < 0 || ($discountType === DiscountType::Percent && $value > 100)) {
            throw ValidationException::withMessages(['discount' => __('pos.order.errors.discount')]);
        }

        $rule = ['type' => $value > 0 ? $discountType?->value : null, 'value' => $value > 0 && $discountType ? $value : 0];
        $subtotal = $draft->price($this->order)['totals']->subtotal;

        if ($draft->discountNeedsApproval(DiscountType::tryFrom((string) $rule['type']), $rule['value'], $subtotal)) {
            $this->pendingDiscount = $rule;
            $value = $rule['type'] === DiscountType::Percent->value ? $rule['value'].'%' : self::rupiah($rule['value']);
            $this->dispatch('approval-required', text: __('pos.order.approval_sub', ['value' => $value, 'limit' => app(Settings::class)->get('discounts.approval_above_pct')]));

            return;
        }

        $this->order['discount'] = $rule + ['approved_by' => null];
        $this->persist();
    }

    public function approve(Approval $approval, OrderDraft $draft, Checkout $checkout, string $pin): void
    {
        [, $device] = $this->context();

        if ($this->pendingHeld) {
            $this->approveHeld($approval, $checkout, $device, $pin);

            return;
        }

        if (! $this->pendingDiscount) {
            return;
        }

        try {
            $approver = $approval->verify($device, $pin, 'discount', $this->pendingDiscount);
        } catch (PosLoginException $e) {
            $this->dispatch('approval-failed', message: $e->getMessage());

            return;
        }

        // Persetujuan berlaku untuk subtotal saat ini: diskon nominal tidak boleh jadi lebih besar (persen) bila item dikurangi.
        $this->order['discount'] = $this->pendingDiscount + ['approved_by' => $approver->id, 'approved_subtotal' => $draft->price($this->order)['totals']->subtotal];
        $this->pendingDiscount = null;
        $this->persist();
        $this->dispatch('approval-done');
        $this->dispatch('toast', message: __('pos.order.discount_approved', ['name' => $approver->name]));
    }

    /** PIN penyetuju untuk membuka open bill lewat hari (dicatat di pesanan & log audit). */
    private function approveHeld(Approval $approval, Checkout $checkout, Device $device, string $pin): void
    {
        $order = Order::open()->find($this->pendingHeld);

        try {
            $approver = $order ? $approval->verify($device, $pin, 'open_bill_day', ['order_id' => $order->id, 'business_date' => $order->business_date->toDateString()]) : null;
        } catch (PosLoginException $e) {
            $this->dispatch('approval-failed', message: $e->getMessage());

            return;
        }

        $this->pendingHeld = null;
        $this->dispatch('approval-done');

        if (! $order) {
            $this->dispatch('toast', message: __('pos.held.errors.not_open'));

            return;
        }

        $order->forceFill(['day_carry_approved_by' => $approver->id])->save();
        $this->loadHeld($checkout, $order);
    }

    public function cancelApproval(): void
    {
        $this->pendingHeld = null;
        $this->pendingDiscount = null;
    }

    /* ---------------------------------------------------------------- bayar */

    /** Bayar pesanan: semua divalidasi & dihitung ulang di server (Checkout). */
    public function pay(Checkout $checkout, int $methodId, ?int $bankId = null, int $received = 0, bool $printReceipt = true): void
    {
        [$user, $device] = $this->context();

        try {
            $result = $checkout->pay($user, $device, $this->order, $this->customerName, $methodId, $bankId, $received, $printReceipt);
            $order = $result->order;
        } catch (ValidationException $e) {
            $this->checkoutFailed($e);

            return;
        }

        $payment = $order->payments()->first();
        $this->resetDraft();

        $this->dispatch('paid',
            cash: $payment->method_kind === PaymentKind::Cash,
            change: $payment->change,
            received: $payment->received,
            total: $order->total,
            message: __('pos.pay.done', ['queue' => $order->queue_number, 'name' => $order->customer_name ?? '']),
            orderId: $order->id,
            // Tiket barista (hanya item yang belum dicetak) lalu struk (bisa dimatikan per transaksi).
            job: $this->printJob([
                $this->ticketEnabled() ? app(Documents::class)->ticket($order, $result->ticket, $result->isAddition) : null,
                $printReceipt ? app(Documents::class)->receipt($order) : null,
            ]),
        );
    }

    /* ---------------------------------------------------------------- open bill (simpan, bayar nanti) */

    public function hold(Checkout $checkout): void
    {
        [$user, $device] = $this->context();

        try {
            $result = $checkout->hold($user, $device, $this->order, $this->customerName);
        } catch (ValidationException $e) {
            $this->checkoutFailed($e);

            return;
        }

        $this->resetDraft();
        $order = $result->order;
        $this->dispatch('held');
        $this->dispatch('print', job: $this->printJob([$this->ticketEnabled() ? app(Documents::class)->ticket($order, $result->ticket, $result->isAddition) : null]));
        $this->dispatch('toast', message: __($result->isAddition ? 'pos.held.updated' : 'pos.held.saved', ['queue' => $order->queue_number, 'name' => $order->customer_name ?? '']));
    }

    /** Buka pesanan tersimpan untuk menambah item atau membayar. */
    public function openHeld(Checkout $checkout, int $orderId): void
    {
        $this->context();
        $order = Order::open()->find($orderId);

        if (! $order) {
            $this->dispatch('toast', message: __('pos.held.errors.not_open'));

            return;
        }

        if ($order->needsDayApproval()) {
            $this->pendingHeld = $order->id;
            $this->dispatch('approval-required', text: __('pos.held.day_approval', ['date' => $order->business_date->translatedFormat('j M'), 'code' => $order->code]));

            return;
        }

        $this->loadHeld($checkout, $order);
    }

    private function loadHeld(Checkout $checkout, Order $order): void
    {
        $draft = $checkout->toDraft($order);
        $this->order = $draft['state'];
        $this->customerName = $draft['name'];
        $this->pendingDiscount = null;
        $this->persist();
        $this->dispatch('toast', message: __('pos.held.opened', ['queue' => $order->queue_number, 'name' => $order->customer_name ?? '']));
    }

    /* ---------------------------------------------------------------- koreksi (void item, refund, batal) */

    private function requestItemVoid(int $index, int $qty, ?array $newLine): void
    {
        $current = $this->order['lines'][$index];
        $printed = (int) $current['printed'];
        $removed = $newLine ? $printed : $printed - max(0, $qty);
        $name = MenuItem::withTrashed()->find($current['item_id'])?->name;

        $signature = OrderDraft::signature($current['item_id'], $current['picks'], $current['note']);
        $this->pendingCorrection = ['kind' => 'item', 'index' => $index, 'signature' => $signature, 'qty' => $qty, 'line' => $newLine, 'removed' => $removed, 'name' => $name];
        $this->dispatch('correction-required', kind: 'item', needsPin: true,
            title: __('pos.corrections.item_title'),
            sub: __('pos.corrections.item_sub', ['qty' => $removed, 'item' => $name]));
    }

    public function startRefund(int $orderId): void
    {
        $this->context();
        $order = self::refundableQuery()->find($orderId);

        if (! $order) {
            $this->dispatch('toast', message: __('pos.corrections.errors.refund_today'));

            return;
        }

        $this->pendingCorrection = ['kind' => 'refund', 'order_id' => $order->id];
        $this->dispatch('correction-required', kind: 'refund', needsPin: (bool) app(Settings::class)->get('policy.refund_needs_approval'),
            title: __('pos.corrections.refund_title'),
            sub: __('pos.corrections.order_sub', ['code' => $order->code, 'name' => $order->customer_name ?? '', 'total' => self::rupiah($order->total)]));
    }

    public function startVoid(int $orderId): void
    {
        $this->context();
        $order = Order::open()->find($orderId);

        if (! $order) {
            return;
        }

        $this->pendingCorrection = ['kind' => 'void', 'order_id' => $order->id];
        $this->dispatch('correction-required', kind: 'void', needsPin: true,
            title: __('pos.corrections.void_title'),
            sub: __('pos.corrections.order_sub', ['code' => $order->code, 'name' => $order->customer_name ?? '', 'total' => self::rupiah($order->total)]));
    }

    public function confirmCorrection(OrderCorrections $corrections, Approval $approval, ShiftService $shifts, int $reasonId, string $note = '', string $pin = ''): void
    {
        [$user, $device] = $this->context();
        $pc = $this->pendingCorrection;

        if (! $pc) {
            return;
        }

        $type = $pc['kind'] === 'refund' ? ReasonType::Refund : ReasonType::Void;
        $needsPin = $pc['kind'] !== 'refund' || app(Settings::class)->get('policy.refund_needs_approval');

        try {
            $reason = $corrections->reason($reasonId, $type, $note);
            $approver = $needsPin ? $approval->verify($device, $pin, $pc['kind'] === 'item' ? 'void_item' : ($pc['kind'] === 'refund' ? 'refund' : 'void_order'), ['reason' => $reason->label] + array_intersect_key($pc, array_flip(['order_id', 'name', 'removed']))) : null;

            match ($pc['kind']) {
                'item' => $this->applyItemVoid($pc, $reason, $note, $approver),
                'refund' => $this->refund($corrections, $shifts, $pc['order_id'], $user, $device, $approver, $reason->id, $note),
                'void' => $corrections->voidOpenBill(Order::findOrFail($pc['order_id']), $user, $approver, $reason->id, $note, $device),
            };
        } catch (ValidationException|PosLoginException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->dispatch('correction-failed', message: $message);

            return;
        }

        if ($pc['kind'] === 'void' && ($this->order['order_id'] ?? null) === $pc['order_id']) {
            $this->resetDraft();
        }

        $this->pendingCorrection = null;
        $this->dispatch('correction-done');
        $this->dispatch('toast', message: __('pos.corrections.done_'.$pc['kind']));
    }

    public function cancelCorrection(): void
    {
        $this->pendingCorrection = null;
    }

    public function selectHistory(?int $orderId): void
    {
        $this->context();
        // Hanya transaksi yang tampil di "Riwayat hari ini" (lebih lama → panel admin).
        $this->historyId = $orderId && self::historyQuery()->whereKey($orderId)->exists() ? $orderId : null;
    }

    /** Riwayat di kasir: open bill + transaksi yang dibuat atau dibayar hari ini. */
    private static function historyQuery(): Builder
    {
        $today = BusinessDate::today()->toDateString();

        return Order::query()->where(fn ($q) => $q->where('status', OrderStatus::Open)
            ->orWhereDate('business_date', $today)
            ->orWhereDate('paid_business_date', $today));
    }

    /** Pesanan yang boleh direfund dari kasir: lunas & dibayar hari ini (lebih lama → panel admin). */
    private static function refundableQuery(): Builder
    {
        return Order::where('status', OrderStatus::Paid)->whereDate('paid_business_date', BusinessDate::today()->toDateString());
    }

    /** @throws ValidationException */
    private function refund(OrderCorrections $corrections, ShiftService $shifts, int $orderId, User $user, Device $device, ?User $approver, int $reasonId, string $note): void
    {
        $order = self::refundableQuery()->find($orderId);
        $drawer = $shifts->running($device);

        if (! $order) {
            throw ValidationException::withMessages(['order' => __('pos.corrections.errors.refund_today')]);
        }

        // Uang refund keluar dari laci shift berjalan tablet ini (masuk hitungan kas saat tutup shift).
        if (! $drawer) {
            throw ValidationException::withMessages(['order' => __('pos.pay.errors.no_shift')]);
        }

        $corrections->refund($order, $user, $approver, $reasonId, $note, $drawer, $device);
    }

    private function applyItemVoid(array $pc, Reason $reason, string $note, ?User $approver): void
    {
        $current = $this->order['lines'][$pc['index']] ?? null;

        // Baris bergeser/berubah sejak void diminta → batalkan, kasir mengulang dari baris yang benar.
        if (! $current || OrderDraft::signature($current['item_id'], $current['picks'], $current['note']) !== $pc['signature']) {
            throw ValidationException::withMessages(['item' => __('pos.corrections.errors.line_changed')]);
        }

        $this->order['voids'][] = [
            'signature' => OrderDraft::signature($current['item_id'], $current['picks'], $current['note']),
            'name' => $pc['name'],
            'qty' => $pc['removed'],
            'reason' => $reason->label,
            'note' => trim($note) !== '' ? trim($note) : null,
            'approved_by' => $approver?->id,
        ];

        if ($pc['line']) {
            $this->order['lines'][$pc['index']] = $pc['line'] + ['printed' => 0];
        } elseif ($pc['qty'] <= 0) {
            array_splice($this->order['lines'], $pc['index'], 1);
        } else {
            $this->order['lines'][$pc['index']]['qty'] = $pc['qty'];
            $this->order['lines'][$pc['index']]['printed'] = min((int) $current['printed'], $pc['qty']);
        }

        $this->persist();
    }

    /* ---------------------------------------------------------------- cetak */

    /** Cetak ulang tiket & struk (dari notifikasi atau Riwayat). */
    public function reprint(Documents $documents, int $orderId): void
    {
        $this->context();
        // Hanya transaksi yang tampil di "Riwayat hari ini" (struk lama tidak bisa dicetak/dilihat dengan menebak id).
        $order = self::historyQuery()->whereIn('status', [OrderStatus::Paid, OrderStatus::Open, OrderStatus::Refunded])->find($orderId);

        if ($order) {
            $this->dispatch('print', job: $this->printJob([
                $documents->ticket($order, reprint: true),
                $order->status !== OrderStatus::Open ? $documents->receipt($order, reprint: true) : null,
            ]));
        }
    }

    public function testPrint(Documents $documents): void
    {
        $this->context();
        $this->dispatch('print', job: $this->printJob([$documents->testPage()]));
    }

    private function printJob(array $docs): ?array
    {
        return app(Documents::class)->job($docs);
    }

    private function ticketEnabled(): bool
    {
        return (bool) app(Settings::class)->get('printing.ticket');
    }

    private function checkoutFailed(ValidationException $e): void
    {
        $errors = $e->errors();

        if (isset($errors['customerName'])) {
            $this->dispatch('name-required');
        }

        $this->dispatch('pay-failed', message: collect($errors)->flatten()->first());
    }

    private function resetDraft(): void
    {
        $this->order = OrderDraft::blank($this->order['type_id'] ?? OrderType::active()->value('id'));
        $this->customerName = '';
        $this->pendingDiscount = null;
        $this->persist();
    }

    private function persist(): void
    {
        session([self::DRAFT => $this->order + ['name' => $this->customerName]]);
    }

    /* ---------------------------------------------------------------- kas masuk/keluar & tutup shift */

    public function addCash(ShiftService $shifts, Approval $approval, string $type, int $amount, int $reasonId, string $note = '', string $pin = ''): void
    {
        [$user, $device] = $this->context();
        $kind = CashMovementType::tryFrom($type);

        try {
            if (! $kind) {
                throw ValidationException::withMessages(['type' => __('pos.cash.errors.amount')]);
            }

            $approver = $kind === CashMovementType::Out && $shifts->cashOutNeedsApproval($amount, $shifts->running($device))
                ? $approval->verify($device, $pin, 'cash_out', ['amount' => $amount])
                : null;
            $movement = $shifts->addCashMovement($user, $device, $kind, $amount, $reasonId, $note, $approver);
        } catch (ValidationException|PosLoginException $e) {
            $this->dispatch('cash-failed', message: $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());

            return;
        }

        $this->dispatch('cash-done');
        $this->dispatch('toast', message: __('pos.cash.done_'.$kind->value, ['amount' => self::rupiah($movement->amount)]));
    }

    /** Langkah "hasil" tutup shift: dihitung di server setelah kasir selesai menghitung (hitung buta). */
    public function closingPreview(ShiftService $shifts, array $counts = [], int $coins = 0, bool $dayClose = false): ?array
    {
        [, $device] = $this->context();
        $shift = $shifts->running($device);

        if (! $shift) {
            return null;
        }

        try {
            $preview = $shifts->closingPreview($shift, $counts, $coins, $dayClose);
        } catch (ValidationException $e) {
            $this->dispatch('close-failed', message: collect($e->errors())->flatten()->first());

            return null;
        }

        // Hitung buta: hitungan pertama diingat di server; hitung ulang tercatat di audit saat shift ditutup.
        $key = 'pos.closing_counts.'.$shift->id;
        $seen = session($key, ['first' => $preview['counted'], 'previews' => 0]);
        session([$key => ['first' => $seen['first'], 'previews' => $seen['previews'] + 1]]);

        return $preview;
    }

    public function closeShift(ShiftService $shifts, Approval $approval, array $input = [], string $pin = ''): void
    {
        [$user, $device] = $this->context();
        $shift = $shifts->running($device);

        if (! $shift) {
            return;
        }

        try {
            $preview = $shifts->closingPreview($shift, $input['counts'] ?? [], (int) ($input['coins'] ?? 0), (bool) ($input['day_close'] ?? false));
            $approver = $preview['variance_needs_approval'] || $preview['carry_needs_approval']
                ? $approval->verify($device, $pin, 'shift_close', ['shift_id' => $shift->id, 'variance' => $preview['variance'], 'open_bills' => $preview['open_bills']['count']])
                : null;
            $closed = $shifts->close($user, $device, $input, $approver);
        } catch (ValidationException|PosLoginException $e) {
            $this->dispatch('close-failed', message: $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());

            return;
        }

        $seen = session()->pull('pos.closing_counts.'.$shift->id);

        if ($seen && $seen['first'] !== $preview['counted']) {
            AuditLog::record('shift.recounted', $closed, ['counted' => $seen['first']], ['counted' => $preview['counted'], 'previews' => $seen['previews']], user: $user, device: $device);
        }

        $this->dispatch('shift-closed', job: $this->printJob([app(Documents::class)->shiftReport($closed->fresh())]));
        $this->dispatch('toast', message: __('pos.close.done', ['name' => $closed->name, 'deposit' => self::rupiah($closed->deposit_amount)]));
    }

    /* ---------------------------------------------------------------- shift */

    public function openShift(ShiftService $shifts, int $templateId, array $counts = [], int $coins = 0, string $note = ''): void
    {
        [$user, $device] = $this->context();
        $shift = $shifts->open($user, $device, $templateId, $counts, $coins, $note);

        $this->dispatch('toast', message: __('pos.shift.opened', ['name' => $shift->name, 'cashier' => $user->name, 'amount' => self::rupiah($shift->opening_float)]));
    }

    /** @return array{0: User, 1: Device} */
    private function context(): array
    {
        $device = app(PosLogin::class)->currentDevice(request());
        $user = $device ? app(PosSession::class)->user($device) : null;
        abort_unless($user && $device, 403);

        return [$user, $device];
    }

    public static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    public function render(ShiftService $shifts, Settings $settings, OrderDraft $draft): View
    {
        [$user, $device] = $this->context();
        $shift = $shifts->running($device);
        $menu = $draft->menu();
        $today = BusinessDate::today();
        $queue = (int) OrderSequence::whereDate('business_date', $today->toDateString())->value('last_number') + 1;
        $data = [
            'user' => $user,
            'device' => $device,
            'shift' => $shift,
            'storeName' => $settings->get('store.name'),
            'autoLock' => (int) $settings->get('policy.auto_lock_minutes'),
            'menu' => $menu,
            'categories' => Category::whereIn('id', $menu->pluck('category_id')->unique())->orderBy('sort')->get(['id', 'name']),
            'menuJson' => $menu->mapWithKeys(fn (MenuItem $item) => [$item->id => [
                'name' => $item->name,
                'category' => $item->category_id,
                'price' => $item->price,
                'station' => $item->category->station->name,
                'groups' => $item->modifierGroups->filter(fn (ModifierGroup $g) => $g->options->isNotEmpty())->values()->map(fn (ModifierGroup $g) => [
                    'id' => $g->id,
                    'name' => $g->name,
                    'default' => $draft->defaultOption($g)?->id,
                    'options' => $g->options->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'price' => $o->price_delta])->values(),
                ]),
            ]]),
            'imageUrl' => fn (MenuItem $item) => $item->image_path ? Storage::disk('public')->url($item->image_path) : null,
            'orderTypes' => OrderType::active()->get(),
            'held' => Order::open()->withCount('items')->orderBy('created_at')->get(),
            'history' => self::historyQuery()->with('payments')->latest('id')->limit(200)->get(),
            'historyOrder' => $this->historyId ? self::historyQuery()->with(['items.modifiers', 'payments', 'refunds', 'cashier', 'creator'])->find($this->historyId) : null,
            'heldOrder' => $this->order['order_id'] ? Order::find($this->order['order_id']) : null,
            'maxDays' => (int) $settings->get('policy.open_bill_max_days'),
            'priced' => $draft->price($this->order),
            'queue' => $queue,
            'orderCode' => Order::makeCode($today, $queue),
            'presets' => DiscountPreset::active()->get(),
            'charges' => $settings->group('charges'),
            'requireName' => (bool) $settings->get('policy.require_customer_name'),
            'customersOn' => $customersOn = app(Customers::class)->enabled(),
            'askPhone' => (bool) $settings->get('customers.ask_phone'),
            'linkedCustomer' => $customersOn && $this->order['customer_id'] ? Customer::find($this->order['customer_id']) : null,
            'loyalty' => (bool) $settings->get('customers.loyalty_enabled'),
            'pointValue' => (int) $settings->get('customers.point_value'),
            'redeemMin' => (int) $settings->get('customers.redeem_min_points'),
        ];
        $data['denominations'] = Denomination::active()->orderByDesc('value')->pluck('value')->all();
        $data['kasirConfig'] = [
            'autoLock' => $data['autoLock'],
            'menu' => $data['menuJson'],
            'pinLength' => (int) $settings->get('policy.pin_length'),
            'ownPinLength' => app(PosLogin::class)->pinLengthFor($user),
            'tableMax' => OrderDraft::TABLE_MAX,
            'methods' => PaymentMethod::active()->get(['id', 'name', 'kind', 'requires_bank', 'qr_image'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'cash' => $m->kind === PaymentKind::Cash, 'bank' => $m->requires_bank, 'qr' => $m->kind === PaymentKind::Cash ? null : $m->qrImageUrl()])->values(),
            'banks' => Bank::active()->get(['id', 'name']),
            'receiptDefault' => (bool) $settings->get('printing.receipt_default'),
            'requireName' => $data['requireName'],
            'customers' => $data['customersOn'],
            'maxReceived' => Checkout::MAX_RECEIVED,
            'reasons' => [
                'void' => Reason::active()->ofType(ReasonType::Void)->get(['id', 'label', 'requires_note']),
                'refund' => Reason::active()->ofType(ReasonType::Refund)->get(['id', 'label', 'requires_note']),
            ],
            'noteMax' => OrderCorrections::NOTE_MAX,
            'cashReasons' => [
                'out' => Reason::active()->ofType(ReasonType::CashOut)->get(['id', 'label', 'requires_note']),
                'in' => Reason::active()->ofType(ReasonType::CashIn)->get(['id', 'label', 'requires_note']),
            ],
            'cashOutLimit' => (int) $settings->get('policy.cash_out_approval_above'),
            'cashOutShiftLimit' => (int) $settings->get('policy.cash_out_shift_approval_above'),
            'shiftCashOut' => $shift ? (int) $shift->cashMovements()->where('type', CashMovementType::Out)->sum('amount') : 0,
            'carryOpenBills' => (bool) $settings->get('policy.carry_open_bills'),
            'varianceLimit' => (int) $settings->get('policy.variance_approval_above'),
            'denoms' => $data['denominations'],
            'shift' => $shift ? ['name' => $shift->name, 'float' => $shift->opening_float, 'lastShift' => $shifts->isLastShift($shift), 'cashier' => $shift->opener?->name, 'opened' => $shift->opened_at->format('H:i')] : null,
        ];

        if ($shift) {
            $data['shiftSummary'] = $shift->summarize();
            $data['cashMovements'] = $shift->cashMovements()->with('approver')->latest('id')->get();
            // Hitung buta: total & penjualan tunai tidak ditampilkan di kasir (kas seharusnya bisa dihitung dari situ).
            $data['shiftMethods'] = PaymentMethod::active()->where('kind', '!=', PaymentKind::Cash)->pluck('name')->all();
        }

        if (! $shift) {
            $handover = $shifts->handoverFrom($device);
            $openBills = Order::open();
            $data += [
                'templates' => ShiftTemplate::active()->get(['id', 'name']),
                'suggested' => $shifts->suggestTemplate($device)?->id,
                'handover' => $handover?->float_left !== null ? ['amount' => $handover->float_left, 'name' => $handover->name, 'cashier' => $handover->closer?->name ?? $handover->opener?->name] : null,
                'openBills' => ['count' => (clone $openBills)->count(), 'total' => (int) (clone $openBills)->sum('total')],
            ];
        }

        return view('livewire.pos.kasir', $data)->layoutData([
            'title' => __('pos.kasir.title'),
            'storeName' => $settings->get('store.name'),
            'css' => 'resources/css/kasir.css',
            'js' => 'resources/js/printer.js',
        ]);
    }
}
