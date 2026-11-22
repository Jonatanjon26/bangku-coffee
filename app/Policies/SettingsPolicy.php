<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Bank;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\EdcSettlementLine;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\ShiftTemplate;
use Illuminate\Database\Eloquent\Model;

/** Pengaturan toko (jenis pesanan, pembayaran, bank, shift, pecahan, alasan, diskon, perangkat): hak "settings". */
class SettingsPolicy extends PermissionPolicy
{
    protected function permission(): Permission
    {
        return Permission::Settings;
    }

    protected function canDelete(Model $model): bool
    {
        // Minimal satu jenis pesanan/metode bayar/pecahan/shift harus tetap aktif.
        $mustKeepOne = [OrderType::class, PaymentMethod::class, Denomination::class, ShiftTemplate::class];

        if (in_array($model::class, $mustKeepOne, true) && $model->is_active && ! $model::where('is_active', true)->whereKeyNot($model->getKey())->exists()) {
            return false;
        }

        return match (true) {
            $model instanceof OrderType => ! Order::where('order_type_id', $model->id)->exists(),
            $model instanceof PaymentMethod => ! Payment::where('payment_method_id', $model->id)->exists()
                && ! Refund::where('payment_method_id', $model->id)->exists()
                && ! EdcSettlementLine::where('payment_method_id', $model->id)->exists(),
            $model instanceof Bank => ! Payment::where('bank_id', $model->id)->exists()
                && ! EdcSettlementLine::where('bank_id', $model->id)->exists(),
            $model instanceof Device => false, // perangkat dicabut, tidak dihapus
            default => true,
        };
    }
}
