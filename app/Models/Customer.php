<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'phone', 'notes', 'contact_consent'])]
class Customer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'contact_consent' => 'boolean',
            'points' => 'integer',
            'visits_count' => 'integer',
            'total_spent' => 'integer',
            'last_visit_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $customer) {
            $customer->phone = self::normalizePhone($customer->phone);
        });
    }

    /** "+62 812-3456" / "62812..." → "0812..." (sama dengan normPhone di mockup). Kosong → null. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/^\+?62/', '0', preg_replace('/[^\d+]/', '', (string) $phone));

        return $digits === '' ? null : $digits;
    }

    /** No. HP untuk layar kasir: 4 digit awal & 3 digit akhir, sisanya disamarkan (0812*****890). Nomor lengkap hanya di admin. */
    public function maskedPhone(): ?string
    {
        $phone = (string) $this->phone;

        return $phone === '' ? null : substr($phone, 0, 4).str_repeat('*', max(0, strlen($phone) - 7)).substr($phone, -3);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Hapus data pelanggan atas permintaan: nama di pesanan dikosongkan, baris pelanggan dihapus.
     * Log audit tidak menyimpan data pribadi.
     */
    public function erase(?User $by = null): void
    {
        DB::transaction(function () use ($by) {
            $orders = $this->orders()->count();
            $this->orders()->update(['customer_id' => null, 'customer_name' => null]);
            AuditLog::record('customer.erased', null, null, ['customer_id' => $this->id, 'orders' => $orders], user: $by);
            $this->delete();
        });
    }
}
