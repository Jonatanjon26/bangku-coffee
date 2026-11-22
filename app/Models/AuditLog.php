<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Log audit hanya-tambah (refund, batal, selisih kas, kas keluar, perubahan harga & pengaturan, ...).
 * Ubah/hapus ditolak di sini dan oleh trigger database.
 */
#[Fillable(['event', 'auditable_type', 'auditable_id', 'user_id', 'approved_by', 'device_id', 'old_values', 'new_values', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'json',
            'new_values' => 'json',
        ];
    }

    protected static function booted(): void
    {
        $deny = fn () => throw new LogicException(__('pos.audit.append_only'));

        static::updating($deny);
        static::deleting($deny);
    }

    /**
     * Catat satu kejadian. Pengguna & IP diambil dari permintaan berjalan bila tidak diisi.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function record(string $event, ?Model $auditable = null, ?array $old = null, ?array $new = null, ?User $approvedBy = null, ?User $user = null, ?Device $device = null): self
    {
        $request = app()->runningInConsole() ? null : request();

        return self::create([
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'user_id' => ($user ?? auth()->user())?->getKey(),
            'approved_by' => $approvedBy?->id,
            'device_id' => $device?->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
