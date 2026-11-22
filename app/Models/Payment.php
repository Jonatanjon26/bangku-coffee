<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PaymentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pembayaran (salinan metode & bank). business_date = hari penjualan dihitung. */
#[Fillable(['order_id', 'payment_method_id', 'method_name', 'method_kind', 'bank_id', 'bank_name', 'amount', 'received', 'change', 'reference', 'shift_id', 'business_date', 'user_id', 'paid_at'])]
class Payment extends Model
{
    protected function casts(): array
    {
        return [
            'method_kind' => PaymentKind::class,
            'amount' => 'integer',
            'received' => 'integer',
            'change' => 'integer',
            'business_date' => DateOnly::class,
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
