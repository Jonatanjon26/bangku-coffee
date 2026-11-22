<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Tablet kasir terdaftar. Token asli hanya di tablet; server menyimpan sha256-nya. */
#[Fillable(['name'])]
#[Hidden(['token_hash'])]
class Device extends Model
{
    use Audited;

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Daftarkan perangkat baru. Mengembalikan [perangkat, token asli] — token hanya ada di sini.
     *
     * @return array{0: self, 1: string}
     */
    public static function register(string $name, ?User $by = null): array
    {
        $token = Str::random(64);
        $device = new self(['name' => $name]);
        $device->forceFill([
            'token_hash' => self::hashToken($token),
            'registered_at' => now(),
            'registered_by' => $by?->id,
        ])->save();

        return [$device, $token];
    }

    public static function findByToken(?string $token): ?self
    {
        return blank($token) ? null : self::active()->where('token_hash', self::hashToken($token))->first();
    }

    public function revoke(?User $by = null): void
    {
        $this->forceFill(['revoked_at' => now(), 'revoked_by' => $by?->id])->save();
    }

    public function unrevoke(): void
    {
        $this->forceFill(['revoked_at' => null, 'revoked_by' => null])->save();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
