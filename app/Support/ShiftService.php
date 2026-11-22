<?php

namespace App\Support;

use App\Enums\CashCountStage;
use App\Enums\CashMovementType;
use App\Enums\PaymentKind;
use App\Enums\Permission;
use App\Enums\ReasonType;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\EdcSettlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Shift;
use App\Models\ShiftCashCount;
use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shift kasir per tablet. Buka shift: modal dihitung per pecahan (koin sebagai total),
 * serah terima dari shift sebelumnya di tablet yang sama (catatan wajib bila berbeda).
 * Semua angka dihitung ulang di server dari hitungan per pecahan, bukan dari total di browser.
 */
class ShiftService
{
    /** Batas wajar input (sama dengan keypad mockup: 3 digit lembar, 7 digit koin). */
    public const MAX_QUANTITY = 999;

    public const MAX_COINS = 9_999_999;

    public const NOTE_MAX = 60;

    public const MAX_AMOUNT = 999_999_999;

    public function __construct(private readonly Settings $settings) {}

    public function running(Device $device): ?Shift
    {
        return Shift::running()->where('device_id', $device->id)->latest('opened_at')->first();
    }

    /** Shift terakhir di tablet ini yang sudah ditutup — sumber serah terima modal. */
    public function handoverFrom(Device $device): ?Shift
    {
        $last = Shift::where('device_id', $device->id)->latest('opened_at')->latest('id')->first();

        return $last && ! $last->isOpen() ? $last : null;
    }

    /** Saran shift: menurut jam mulai; bila shift itu sudah dibuka hari ini di tablet ini, shift berikutnya yang belum. */
    public function suggestTemplate(Device $device, ?CarbonImmutable $now = null): ?ShiftTemplate
    {
        $now ??= CarbonImmutable::now();
        $templates = ShiftTemplate::active()->get();
        $done = Shift::where('device_id', $device->id)->whereDate('business_date', $now->toDateString())->pluck('name')->all();
        $time = $now->format('H:i');
        $byTime = $templates->filter(fn (ShiftTemplate $t) => substr($t->starts_at, 0, 5) <= $time)->last() ?? $templates->first();

        if ($byTime && in_array($byTime->name, $done, true)) {
            return $templates->first(fn (ShiftTemplate $t) => ! in_array($t->name, $done, true)) ?? $byTime;
        }

        return $byTime;
    }

    /**
     * @param  array<int|string, mixed>  $counts  pecahan => jumlah lembar
     *
     * @throws ValidationException
     */
    public function open(User $user, Device $device, int $templateId, array $counts, int $coins, ?string $note): Shift
    {
        if (! $user->hasPermission(Permission::Pos)) {
            throw ValidationException::withMessages(['shift' => __('pos.shift.errors.not_allowed')]);
        }

        $template = ShiftTemplate::active()->find($templateId);

        if (! $template) {
            throw ValidationException::withMessages(['template' => __('pos.shift.errors.template')]);
        }

        [$rows, $total] = $this->countRows($counts, $coins);
        $note = trim((string) $note);

        return DB::transaction(function () use ($user, $device, $template, $rows, $total, $note) {
            // Kunci baris tablet: dua permintaan bersamaan tidak bisa membuka dua shift.
            Device::whereKey($device->id)->lockForUpdate()->first();

            if ($this->running($device)) {
                throw ValidationException::withMessages(['shift' => __('pos.shift.errors.already_open')]);
            }

            $previous = $this->handoverFrom($device);
            $expected = $previous?->float_left;

            if ($expected !== null && $expected !== $total && $note === '') {
                throw ValidationException::withMessages(['note' => __('pos.shift.errors.note_required')]);
            }

            if (mb_strlen($note) > self::NOTE_MAX) {
                throw ValidationException::withMessages(['note' => __('validation.max.string', ['attribute' => __('pos.shift.note'), 'max' => self::NOTE_MAX])]);
            }

            $now = CarbonImmutable::now();
            $shift = new Shift([
                'shift_template_id' => $template->id,
                'name' => $template->name,
                'business_date' => BusinessDate::today($now),
                'device_id' => $device->id,
                'opening_float' => $total,
                'previous_shift_id' => $previous?->id,
                'handover_expected' => $expected,
                'handover_note' => $note !== '' ? $note : null,
            ]);
            $shift->forceFill(['opened_by' => $user->id, 'opened_at' => $now])->save();
            $shift->cashCounts()->createMany(array_map(fn (array $row) => $row + ['stage' => CashCountStage::Opening], $rows));

            if ($expected !== null && $expected !== $total) {
                AuditLog::record('shift.handover_difference', $shift, ['expected' => $expected], ['counted' => $total, 'difference' => $total - $expected, 'note' => $note], user: $user, device: $device);
            }

            return $shift;
        });
    }

    /* ---------------------------------------------------------------- kas masuk / keluar */

    /**
     * Kas keluar perlu penyetuju bila satu transaksi di atas batas, atau total kas keluar shift ini
     * (termasuk yang ini) di atas batas per shift (0 = tanpa batas per shift) — tidak bisa dipecah kecil-kecil.
     */
    public function cashOutNeedsApproval(int $amount, ?Shift $shift = null): bool
    {
        $shiftLimit = (int) $this->settings->get('policy.cash_out_shift_approval_above');
        $shiftTotal = $shift ? (int) $shift->cashMovements()->where('type', CashMovementType::Out)->sum('amount') : 0;

        return $amount > (int) $this->settings->get('policy.cash_out_approval_above')
            || ($shift && $shiftLimit > 0 && $shiftTotal + $amount > $shiftLimit);
    }

    /**
     * Catat kas masuk/keluar laci di luar penjualan. Kas keluar di atas batas wajib penyetuju.
     *
     * @throws ValidationException
     */
    public function addCashMovement(User $user, Device $device, CashMovementType $type, int $amount, int $reasonId, ?string $note, ?User $approver): CashMovement
    {
        $shift = $this->running($device);
        $note = trim((string) $note);

        if (! $shift) {
            throw ValidationException::withMessages(['cash' => __('pos.pay.errors.no_shift')]);
        }

        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            throw ValidationException::withMessages(['amount' => __('pos.cash.errors.amount')]);
        }

        $reason = Reason::active()->ofType($type === CashMovementType::In ? ReasonType::CashIn : ReasonType::CashOut)->find($reasonId);

        if (! $reason) {
            throw ValidationException::withMessages(['reason' => __('pos.corrections.errors.reason')]);
        }

        if (($reason->requires_note && $note === '') || mb_strlen($note) > 40) {
            throw ValidationException::withMessages(['note' => __('pos.corrections.errors.note')]);
        }

        return DB::transaction(function () use ($user, $device, $type, $amount, $reason, $note, $approver, $shift) {
            // Dikunci per tablet: dua kas keluar bersamaan tidak bisa sama-sama lolos di bawah batas per shift.
            Device::whereKey($device->id)->lockForUpdate()->first();
            $needsApproval = $type === CashMovementType::Out && $this->cashOutNeedsApproval($amount, $shift);

            if ($needsApproval && ! $approver?->hasPermission(Permission::Approve)) {
                throw ValidationException::withMessages(['approver' => __('pos.corrections.errors.approver')]);
            }

            return $this->saveCashMovement($user, $device, $shift, $type, $amount, $reason, $note, $needsApproval ? $approver : null);
        });
    }

    private function saveCashMovement(User $user, Device $device, Shift $shift, CashMovementType $type, int $amount, Reason $reason, string $note, ?User $approver): CashMovement
    {
        $needsApproval = $approver !== null;
        $movement = new CashMovement([
            'shift_id' => $shift->id,
            'type' => $type,
            'amount' => $amount,
            'reason_id' => $reason->id,
            'reason_label' => $reason->requires_note ? $note : $reason->label.($note !== '' ? ' - '.$note : ''),
            'note' => $note !== '' ? $note : null,
        ]);
        $movement->forceFill(['user_id' => $user->id, 'approved_by' => $needsApproval ? $approver->id : null])->save();

        if ($type === CashMovementType::Out) {
            AuditLog::record('cash.out', $movement, null, ['amount' => $amount, 'reason' => $movement->reason_label, 'shift_id' => $shift->id], approvedBy: $needsApproval ? $approver : null, user: $user, device: $device);
        }

        return $movement;
    }

    /* ---------------------------------------------------------------- tutup shift */

    /**
     * Ringkasan untuk langkah "hasil" tutup shift — hanya setelah kasir selesai menghitung (hitung buta).
     *
     * @return array<string, mixed>
     */
    public function closingPreview(Shift $shift, array $counts, int $coins, bool $dayClose): array
    {
        [, $counted] = $this->countRows($counts, $coins);
        $summary = $shift->summarize();
        $variance = $counted - $summary['expected'];
        $openBills = Order::open();
        $openCount = (clone $openBills)->count();
        $settled = EdcSettlement::whereDate('business_date', $shift->business_date->toDateString())->exists();

        return [
            'summary' => $summary,
            'cash_sales_count' => $shift->payments()->where('method_kind', PaymentKind::Cash)->count(),
            'counted' => $counted,
            'variance' => $variance,
            'variance_needs_approval' => abs($variance) > (int) $this->settings->get('policy.variance_approval_above'),
            'open_bills' => ['count' => $openCount, 'total' => (int) (clone $openBills)->sum('total')],
            'carry_allowed' => (bool) $this->settings->get('policy.carry_open_bills'),
            'carry_needs_approval' => $dayClose && $openCount > 0 && (bool) $this->settings->get('policy.open_bill_day_approval'),
            'edc' => $settled ? [] : array_values($this->edcTotals($shift->business_date)),
            'edc_settled' => $settled,
        ];
    }

    /** Shift terakhir di daftar = tutup hari (settlement EDC). */
    public function isLastShift(Shift $shift): bool
    {
        return ShiftTemplate::active()->get()->last()?->id === $shift->shift_template_id;
    }

    /**
     * Total EDC/QRIS per metode (& bank) dari semua pembayaran pada tanggal itu, untuk dicocokkan dengan
     * struk settlement mesin EDC. Metode EDC aktif tanpa bank selalu tampil (walau Rp 0).
     *
     * @return array<string, array{payment_method_id: int, bank_id: ?int, label: string, pos_amount: int}>
     */
    public function edcTotals(\DateTimeInterface $date): array
    {
        $rows = [];

        foreach (PaymentMethod::active()->where('kind', PaymentKind::Edc)->where('requires_bank', false)->get() as $method) {
            $rows[$method->name] = ['payment_method_id' => $method->id, 'bank_id' => null, 'label' => $method->name, 'pos_amount' => 0];
        }

        // Refund EDC di hari yang sama di-void di mesin EDC, jadi tidak ikut batch settlement.
        $payments = Payment::whereDate('business_date', $date->format('Y-m-d'))->where('method_kind', PaymentKind::Edc)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('refunds')
                ->whereColumn('refunds.order_id', 'payments.order_id')->whereColumn('refunds.business_date', 'payments.business_date'))
            ->selectRaw('payment_method_id, bank_id, method_name, bank_name, SUM(amount) as total')
            ->groupBy('payment_method_id', 'bank_id', 'method_name', 'bank_name')->get();

        foreach ($payments as $p) {
            $label = trim($p->method_name.' '.$p->bank_name);
            $rows[$label] = ['payment_method_id' => $p->payment_method_id, 'bank_id' => $p->bank_id, 'label' => $label, 'pos_amount' => (int) $p->total];
        }

        return $rows;
    }

    /**
     * Tutup shift: hitung buta per pecahan, selisih wajib alasan (di atas batas: penyetuju), modal ditinggal,
     * setoran ke owner, open bill dialihkan (lewat hari: penyetuju), dan saat tutup hari: settlement EDC.
     *
     * @param  array{counts?: array, coins?: int, float_left?: int, variance_note?: ?string, day_close?: bool, edc?: array<string, int>, edc_note?: ?string}  $input
     *
     * @throws ValidationException
     */
    public function close(User $user, Device $device, array $input, ?User $approver): Shift
    {
        [$rows, $counted] = $this->countRows($input['counts'] ?? [], (int) ($input['coins'] ?? 0));
        $dayClose = (bool) ($input['day_close'] ?? false);
        $varianceNote = trim((string) ($input['variance_note'] ?? ''));
        $floatLeft = (int) ($input['float_left'] ?? 0);

        return DB::transaction(function () use ($user, $device, $input, $approver, $rows, $counted, $dayClose, $varianceNote, $floatLeft) {
            Device::whereKey($device->id)->lockForUpdate()->first();
            $shift = $this->running($device);

            if (! $shift) {
                throw ValidationException::withMessages(['shift' => __('pos.pay.errors.no_shift')]);
            }

            $preview = $this->closingPreview($shift, $input['counts'] ?? [], (int) ($input['coins'] ?? 0), $dayClose);
            $variance = $preview['variance'];
            $openBills = Order::open()->get();

            if ($openBills->isNotEmpty() && ! $preview['carry_allowed']) {
                throw ValidationException::withMessages(['shift' => __('pos.close.errors.open_bills')]);
            }

            if ($variance !== 0 && $varianceNote === '') {
                throw ValidationException::withMessages(['variance_note' => __('pos.close.errors.variance_note')]);
            }

            if (mb_strlen($varianceNote) > self::NOTE_MAX) {
                throw ValidationException::withMessages(['variance_note' => __('validation.max.string', ['attribute' => __('pos.close.variance_note'), 'max' => self::NOTE_MAX])]);
            }

            if (($preview['variance_needs_approval'] || $preview['carry_needs_approval']) && ! $approver?->hasPermission(Permission::Approve)) {
                throw ValidationException::withMessages(['approver' => __('pos.corrections.errors.approver')]);
            }

            if ($floatLeft < 0 || $floatLeft > $counted) {
                throw ValidationException::withMessages(['float_left' => __('pos.close.errors.float_left')]);
            }

            $shift->cashCounts()->createMany(array_map(fn (array $row) => $row + ['stage' => CashCountStage::Closing], $rows));

            // Open bill dialihkan ke shift berikutnya (dicatat di laporan shift).
            foreach ($openBills as $order) {
                $shift->carriedOpenBills()->attach($order->id, ['total' => $order->total]);

                if ($dayClose && $preview['carry_needs_approval']) {
                    $order->forceFill(['day_carry_approved_by' => $approver->id])->save();
                }
            }

            $shift->forceFill([
                'closed_by' => $user->id,
                'closed_at' => now(),
                'expected_cash' => $preview['summary']['expected'],
                'counted_cash' => $counted,
                'variance' => $variance,
                'variance_note' => $variance !== 0 ? $varianceNote : null,
                'variance_approved_by' => $preview['variance_needs_approval'] ? $approver->id : null,
                'float_left' => $floatLeft,
                'deposit_amount' => $counted - $floatLeft,
                'carried_open_bills_count' => $openBills->count(),
                'carried_open_bills_total' => (int) $openBills->sum('total'),
                'open_bills_approved_by' => $preview['carry_needs_approval'] ? $approver->id : null,
                'is_day_close' => $dayClose,
                'summary' => $preview['summary'],
            ])->save();

            if ($dayClose && ! $preview['edc_settled']) {
                $this->settle($shift, $user, $preview['edc'], $input['edc'] ?? [], trim((string) ($input['edc_note'] ?? '')));
            }

            if ($variance !== 0) {
                AuditLog::record('shift.variance', $shift, ['expected' => $preview['summary']['expected']], ['counted' => $counted, 'variance' => $variance, 'note' => $varianceNote], approvedBy: $preview['variance_needs_approval'] ? $approver : null, user: $user, device: $device);
            }

            if ($preview['carry_needs_approval']) {
                AuditLog::record('open_bills.carried_over_day', $shift, null, ['count' => $openBills->count(), 'total' => (int) $openBills->sum('total')], approvedBy: $approver, user: $user, device: $device);
            }

            return $shift;
        });
    }

    /** @throws ValidationException */
    private function settle(Shift $shift, User $user, array $posRows, array $edcInput, string $note): void
    {
        $lines = [];
        $hasDifference = false;

        foreach ($posRows as $row) {
            $edc = $edcInput[$row['label']] ?? null;

            if (! is_numeric($edc) || (int) $edc < 0 || (int) $edc > self::MAX_AMOUNT) {
                throw ValidationException::withMessages(['edc' => __('pos.close.errors.edc_missing', ['label' => $row['label']])]);
            }

            $lines[] = $row + ['edc_amount' => (int) $edc, 'difference' => (int) $edc - $row['pos_amount']];
            $hasDifference = $hasDifference || (int) $edc !== $row['pos_amount'];
        }

        if ($hasDifference && $note === '') {
            throw ValidationException::withMessages(['edc_note' => __('pos.close.errors.edc_note')]);
        }

        $settlement = new EdcSettlement(['business_date' => $shift->business_date, 'shift_id' => $shift->id, 'note' => $note !== '' ? $note : null]);
        $settlement->forceFill(['settled_by' => $user->id])->save();
        $settlement->lines()->createMany($lines);

        if ($hasDifference) {
            AuditLog::record('edc.difference', $settlement, null, ['lines' => array_map(fn ($l) => [$l['label'], $l['difference']], array_filter($lines, fn ($l) => $l['difference'] !== 0)), 'note' => $note], user: $user, device: $shift->device);
        }
    }

    /**
     * Baris hitungan kas (hanya pecahan aktif) + total.
     *
     * @param  array<int|string, mixed>  $counts
     * @return array{0: list<array{denomination: int, quantity: int, amount: int}>, 1: int}
     *
     * @throws ValidationException
     */
    public function countRows(array $counts, int $coins): array
    {
        $allowed = Denomination::active()->pluck('value')->all();
        $rows = [];
        $total = 0;

        foreach ($counts as $value => $quantity) {
            $value = (int) $value;

            if (! in_array($value, $allowed, true) || ! is_numeric($quantity) || (int) $quantity != $quantity || $quantity < 0 || $quantity > self::MAX_QUANTITY) {
                throw ValidationException::withMessages(['counts' => __('pos.shift.errors.counts')]);
            }

            if ((int) $quantity > 0) {
                $rows[] = ['denomination' => $value, 'quantity' => (int) $quantity, 'amount' => $value * (int) $quantity];
                $total += $value * (int) $quantity;
            }
        }

        if ($coins < 0 || $coins > self::MAX_COINS) {
            throw ValidationException::withMessages(['counts' => __('pos.shift.errors.counts')]);
        }

        if ($coins > 0) {
            $rows[] = ['denomination' => ShiftCashCount::COINS, 'quantity' => 1, 'amount' => $coins];
            $total += $coins;
        }

        return [$rows, $total];
    }
}
