<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Support\BusinessDate;
use App\Support\OrderTotals;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Pesanan: open bill (status open) maupun yang sudah dibayar. Tidak pernah dihapus;
 * batal & refund mengubah status dan dicatat di log audit.
 * Kolom status, total, pelaku & persetujuan tidak fillable — diisi oleh kode server.
 */
#[Fillable(['customer_id', 'customer_name', 'order_type_id', 'order_type_name', 'table_number', 'discount_type', 'discount_value', 'receipt_printed'])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'business_date' => DateOnly::class,
            'paid_business_date' => DateOnly::class,
            'status' => OrderStatus::class,
            'discount_type' => DiscountType::class,
            'subtotal' => 'integer',
            'discount_value' => 'integer',
            'discount_amount' => 'integer',
            'service_rate' => 'float',
            'service_amount' => 'integer',
            'tax_rate' => 'float',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'points_earned' => 'integer',
            'points_redeemed' => 'integer',
            'points_discount' => 'integer',
            'receipt_printed' => 'boolean',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** Kode pesanan tetap, mis. B-1019-007 (prefiks dari pengaturan orders.code_prefix). */
    public static function makeCode(CarbonInterface $businessDate, int $queueNumber): string
    {
        $prefix = app(Settings::class)->get('orders.code_prefix');

        return sprintf('%s-%s-%03d', $prefix, $businessDate->format('md'), $queueNumber);
    }

    /** Hitung ulang total dari item & pengaturan pajak/service yang berlaku (di server, bukan dari tablet). */
    public function recalculate(?OrderType $type = null): OrderTotals
    {
        $settings = app(Settings::class);
        $type ??= $this->orderType;
        $subtotal = (int) $this->items()->sum(DB::raw('unit_price * quantity'));
        $serviceRate = $settings->get('charges.service_enabled') && $type?->applies_service ? (float) $settings->get('charges.service_rate') : 0.0;
        $taxRate = $settings->get('charges.tax_enabled') ? (float) $settings->get('charges.tax_rate') : 0.0;

        // Potongan tukar poin ikut dihitung (setelah diskon, sebelum service & PB1), sama dengan OrderTotals di kasir.
        $totals = OrderTotals::calculate($subtotal, $this->discount_type, (int) $this->discount_value, $serviceRate, $taxRate, (int) $this->points_discount);

        $this->forceFill([
            'subtotal' => $totals->subtotal,
            'discount_amount' => $totals->discount,
            'points_discount' => $totals->points,
            'service_rate' => $totals->serviceRate,
            'service_amount' => $totals->service,
            'tax_rate' => $totals->taxRate,
            'tax_amount' => $totals->tax,
            'total' => $totals->total,
        ]);

        return $totals;
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', OrderStatus::Open);
    }

    /** Penjualan dihitung saat dibayar: refund tetap tercatat terpisah di tabel refunds. */
    public function scopeSold(Builder $query): void
    {
        $query->whereIn('status', [OrderStatus::Paid, OrderStatus::Refunded])->whereNotNull('paid_at');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Open bill dari hari sebelumnya yang belum disetujui (pengaturan "open bill lewat hari perlu persetujuan").
     * Persetujuan diberikan saat tutup hari atau saat dibuka lagi di kasir; dicek ulang di Checkout.
     */
    public function needsDayApproval(): bool
    {
        return $this->status === OrderStatus::Open
            && $this->business_date?->lt(BusinessDate::today())
            && $this->day_carry_approved_by === null
            && (bool) app(Settings::class)->get('policy.open_bill_day_approval');
    }

    /** Refund hanya di hari bisnis yang sama dengan pembayaran (diputuskan pemilik: tidak ada refund hari berikutnya). */
    public function refundableToday(): bool
    {
        return $this->status === OrderStatus::Paid && $this->paid_business_date?->isSameDay(BusinessDate::today());
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderType::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function openedShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'opened_shift_id');
    }

    public function paidShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'paid_shift_id');
    }

    public function voidReason(): BelongsTo
    {
        return $this->belongsTo(Reason::class, 'void_reason_id');
    }
}
