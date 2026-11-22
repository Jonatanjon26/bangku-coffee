<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\CashMovementType;
use App\Enums\PaymentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shift kasir: buka (modal per pecahan, serah terima), kas masuk/keluar, tutup (hitung buta,
 * selisih, modal ditinggal, setoran). Hasil tutup & persetujuan diisi kode server (tidak fillable).
 */
#[Fillable(['shift_template_id', 'name', 'business_date', 'device_id', 'opening_float', 'previous_shift_id', 'handover_expected', 'handover_note'])]
class Shift extends Model
{
    protected function casts(): array
    {
        return [
            'business_date' => DateOnly::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'deposit_received_at' => 'datetime',
            'opening_float' => 'integer',
            'handover_expected' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'variance' => 'integer',
            'float_left' => 'integer',
            'deposit_amount' => 'integer',
            'carried_open_bills_count' => 'integer',
            'carried_open_bills_total' => 'integer',
            'is_day_close' => 'boolean',
            'summary' => 'json',
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function scopeRunning(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * Ringkasan kas & penjualan shift (dari data, bukan dari tablet).
     * Seharusnya di laci = modal + penjualan tunai − refund tunai + kas masuk − kas keluar.
     *
     * @return array{count: int, total: int, discount: int, by_method: array<string, int>, by_bank: array<string, int>, cash_sales: int, cash_refunds: int, cash_in: int, cash_out: int, expected: int}
     */
    public function summarize(): array
    {
        $payments = $this->payments()->get();
        $byMethod = [];
        $byBank = [];

        foreach ($payments as $p) {
            $byMethod[$p->method_name] = ($byMethod[$p->method_name] ?? 0) + $p->amount;

            if ($p->bank_name) {
                $label = $p->method_name.' '.$p->bank_name;
                $byBank[$label] = ($byBank[$label] ?? 0) + $p->amount;
            }
        }

        $cashSales = (int) $payments->where('method_kind', PaymentKind::Cash)->sum('amount');
        // Refund yang terjadi di shift ini (uang tunai keluar dari laci ini; EDC di-void di mesin).
        $refunds = $this->refunds()->get(['method_name', 'method_kind', 'amount']);
        $cashRefunds = (int) $refunds->where('method_kind', PaymentKind::Cash)->sum('amount');
        $cashIn = (int) $this->cashMovements()->where('type', CashMovementType::In)->sum('amount');
        $cashOut = (int) $this->cashMovements()->where('type', CashMovementType::Out)->sum('amount');

        return [
            'count' => $payments->pluck('order_id')->unique()->count(),
            'total' => (int) $payments->sum('amount'),
            'refunds' => (int) $refunds->sum('amount'),
            'refunds_by_method' => $refunds->groupBy('method_name')->map(fn ($r) => (int) $r->sum('amount'))->all(),
            // Bersih = penjualan − refund di shift ini; per hari sama dengan omzet di Laporan/Dasbor.
            'net' => (int) $payments->sum('amount') - (int) $refunds->sum('amount'),
            'discount' => (int) Order::whereIn('id', $payments->pluck('order_id'))->sum('discount_amount'),
            'by_method' => $byMethod,
            'by_bank' => $byBank,
            'cash_sales' => $cashSales,
            'cash_refunds' => $cashRefunds,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected' => $this->opening_float + $cashSales - $cashRefunds + $cashIn - $cashOut,
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function previousShift(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_shift_id');
    }

    public function cashCounts(): HasMany
    {
        return $this->hasMany(ShiftCashCount::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function carriedOpenBills(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'shift_open_bills')->withPivot('total')->withTimestamps();
    }
}
