<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\PaymentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Refund tidak menghapus pesanan asli. Pelaku & penyetuju diisi kode server (tidak fillable). */
#[Fillable(['order_id', 'amount', 'payment_method_id', 'method_name', 'method_kind', 'reason_id', 'reason_label', 'note', 'shift_id', 'business_date'])]
class Refund extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'method_kind' => PaymentKind::class,
            'business_date' => DateOnly::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
