<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payment_method_id', 'bank_id', 'label', 'pos_amount', 'edc_amount', 'difference'])]
class EdcSettlementLine extends Model
{
    protected function casts(): array
    {
        return [
            'pos_amount' => 'integer',
            'edc_amount' => 'integer',
            'difference' => 'integer',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(EdcSettlement::class, 'edc_settlement_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
