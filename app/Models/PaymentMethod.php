<?php

namespace App\Models;

use App\Enums\PaymentKind;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'kind', 'requires_bank', 'qr_image', 'is_active', 'sort'])]
class PaymentMethod extends Model
{
    use Audited;

    protected function casts(): array
    {
        return ['kind' => PaymentKind::class, 'requires_bank' => 'boolean', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort');
    }

    /** URL publik gambar QR (mis. QRIS toko) untuk dialog bayar kasir; null bila belum diunggah. */
    public function qrImageUrl(): ?string
    {
        return $this->qr_image ? Storage::disk('public')->url($this->qr_image) : null;
    }
}
