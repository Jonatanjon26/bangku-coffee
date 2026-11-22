<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\ReasonType;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Order;
use App\Models\Reason;
use App\Models\Refund;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Koreksi pesanan: refund utuh (sudah dibayar) dan batal open bill (belum dibayar).
 * Pesanan asli tidak pernah dihapus; status berubah dan semuanya tercatat di log audit.
 * Pemanggil memastikan persetujuan (PIN penyetuju di tablet, atau hak di panel admin);
 * refund tanpa penyetuju hanya bila pengaturan policy.refund_needs_approval dimatikan.
 */
class OrderCorrections
{
    public const NOTE_MAX = 100;

    public function __construct(private readonly ShiftService $shifts, private readonly Customers $customers) {}

    /**
     * Refund seluruh transaksi. Uang tunai dikembalikan dari laci shift berjalan ($drawer) bila ada.
     *
     * @throws ValidationException
     */
    public function refund(Order $order, User $by, ?User $approver, int $reasonId, ?string $note, ?Shift $drawer, ?Device $device = null): Refund
    {
        $reason = $this->reason($reasonId, ReasonType::Refund, $note);

        if ($approver) {
            $this->ensureApprover($approver);
        }

        return DB::transaction(function () use ($order, $by, $approver, $reason, $note, $drawer, $device) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Paid) {
                throw ValidationException::withMessages(['order' => __('pos.corrections.errors.not_paid')]);
            }

            // Tidak ada refund hari berikutnya (juga cocok dengan void di mesin EDC sebelum settlement).
            if (! $order->refundableToday()) {
                throw ValidationException::withMessages(['order' => __('pos.corrections.errors.refund_today')]);
            }

            $payment = $order->payments()->firstOrFail();
            $refund = new Refund([
                'order_id' => $order->id,
                'amount' => $order->total,
                'payment_method_id' => $payment->payment_method_id,
                'method_name' => $payment->method_name,
                'method_kind' => $payment->method_kind,
                'reason_id' => $reason->id,
                'reason_label' => $reason->label,
                'note' => $this->note($note),
                'shift_id' => $drawer?->id,
                'business_date' => BusinessDate::today(),
            ]);
            $refund->forceFill(['refunded_by' => $by->id, 'approved_by' => $approver?->id])->save();
            $order->forceFill(['status' => OrderStatus::Refunded])->save();
            $this->customers->reverseVisit($order);

            AuditLog::record('order.refunded', $order, ['status' => OrderStatus::Paid->value], [
                'amount' => $refund->amount, 'method' => $refund->method_name, 'reason' => $reason->label, 'note' => $refund->note, 'shift_id' => $drawer?->id,
            ], approvedBy: $approver, user: $by, device: $device);

            return $refund;
        });
    }

    /**
     * Batalkan open bill dengan alasan (tercatat siapa & kapan).
     *
     * @throws ValidationException
     */
    public function voidOpenBill(Order $order, User $by, User $approver, int $reasonId, ?string $note, ?Device $device = null): Order
    {
        $reason = $this->reason($reasonId, ReasonType::Void, $note);
        $this->ensureApprover($approver);

        return DB::transaction(function () use ($order, $by, $approver, $reason, $note, $device) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Open) {
                throw ValidationException::withMessages(['order' => __('pos.held.errors.not_open')]);
            }

            $order->forceFill([
                'status' => OrderStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $by->id,
                'void_reason_id' => $reason->id,
                'void_reason_label' => $reason->label,
                'void_note' => $this->note($note),
            ])->save();

            AuditLog::record('order.voided', $order, ['status' => OrderStatus::Open->value], [
                'total' => $order->total, 'reason' => $reason->label, 'note' => $order->void_note,
            ], approvedBy: $approver, user: $by, device: $device);

            return $order;
        });
    }

    /** Laci untuk uang refund tunai: shift berjalan di tablet yang menerima pembayaran. */
    public function drawerFor(Order $order): ?Shift
    {
        $deviceId = $order->payments()->first()?->shift?->device_id ?? $order->device_id;

        return $deviceId ? Shift::running()->where('device_id', $deviceId)->latest('opened_at')->first() : null;
    }

    /** @throws ValidationException */
    public function reason(int $reasonId, ReasonType $type, ?string $note): Reason
    {
        $reason = Reason::active()->ofType($type)->find($reasonId);

        if (! $reason) {
            throw ValidationException::withMessages(['reason' => __('pos.corrections.errors.reason')]);
        }

        if ($reason->requires_note && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => __('pos.corrections.errors.note')]);
        }

        if (mb_strlen(trim((string) $note)) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => __('validation.max.string', ['attribute' => __('pos.corrections.note'), 'max' => self::NOTE_MAX])]);
        }

        return $reason;
    }

    /** @throws ValidationException */
    private function ensureApprover(User $approver): void
    {
        if (! $approver->hasPermission(Permission::Approve)) {
            throw ValidationException::withMessages(['approver' => __('pos.corrections.errors.approver')]);
        }
    }

    private function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note !== '' ? $note : null;
    }
}
