<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kode sekali pakai untuk mendaftarkan tablet baru (disimpan sebagai hash). */
#[Fillable(['device_name', 'expires_at'])]
#[Hidden(['code_hash'])]
class DevicePairingCode extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * Buat kode baru. Mengembalikan [baris, kode asli].
     *
     * @return array{0: self, 1: string}
     */
    public static function generate(string $deviceName, \DateTimeInterface $expiresAt, ?User $by = null, ?string $code = null): array
    {
        $code ??= str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $row = new self(['device_name' => $deviceName, 'expires_at' => $expiresAt]);
        $row->forceFill(['code_hash' => self::hashCode($code), 'created_by' => $by?->id])->save();

        return [$row, $code];
    }

    /** Kode yang masih berlaku (belum dipakai, belum kedaluwarsa). */
    public static function findUsable(string $code): ?self
    {
        return self::where('code_hash', self::hashCode($code))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
