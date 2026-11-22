<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Kunci sementara setelah salah berulang (PIN, PIN penyetuju, kode pendaftaran tablet).
 *
 * Jatah percobaan diambil secara atomik SEBELUM verifikasi (take), jadi permintaan paralel tidak bisa
 * melewati batas selagi bcrypt berjalan. Setelah $max kali salah: terkunci, dan lama kunci berlipat
 * setiap kali terkunci lagi dalam jendela eskalasi (mis. 60 s → 120 s → 240 s …, maks. pos.lock_max_seconds).
 */
class AttemptLock
{
    public function __construct(private readonly string $key) {}

    public function lockedFor(): int
    {
        $until = (int) Cache::get($this->key.':until', 0);

        return max(0, $until - now()->getTimestamp());
    }

    public function failures(): int
    {
        return (int) Cache::get($this->key.':fails', 0);
    }

    /**
     * Ambil satu jatah percobaan. False = sedang terkunci atau jatah habis (percobaan lain sedang berjalan).
     * $windowSeconds: lama hitungan disimpan (bawaan pos.attempt_window_seconds).
     */
    public function take(int $max, ?int $windowSeconds = null): bool
    {
        if ($this->lockedFor() > 0) {
            return false;
        }

        Cache::add($this->key.':fails', 0, $windowSeconds ?? (int) config('pos.attempt_window_seconds'));
        $attempt = (int) Cache::increment($this->key.':fails');

        return $attempt <= $max && $this->lockedFor() === 0;
    }

    /** Percobaan (yang sudah diambil lewat take) salah. Mengembalikan lama kunci dalam detik, 0 = belum terkunci. */
    public function failed(int $max, int $lockSeconds): int
    {
        return $this->failures() >= $max ? $this->lock($lockSeconds) : 0;
    }

    /** Percobaan benar: hitungan salah direset (tingkat eskalasi tetap diingat sampai jendelanya habis). */
    public function succeeded(): void
    {
        Cache::forget($this->key.':fails');
    }

    /** Percobaan benar, tetapi hitungan salah lain dibiarkan (kunci gabungan per tablet/global): kembalikan jatahnya. */
    public function release(): void
    {
        if ($this->failures() > 0) {
            Cache::decrement($this->key.':fails');
        }
    }

    public function clear(): void
    {
        Cache::forget($this->key.':fails');
        Cache::forget($this->key.':until');
        Cache::forget($this->key.':level');
    }

    private function lock(int $lockSeconds): int
    {
        Cache::add($this->key.':level', 0, (int) config('pos.lock_escalation_window_seconds'));
        $level = max(1, (int) Cache::increment($this->key.':level'));
        $seconds = (int) min($lockSeconds * 2 ** min($level - 1, 16), max($lockSeconds, (int) config('pos.lock_max_seconds')));

        Cache::put($this->key.':until', now()->getTimestamp() + $seconds, $seconds);
        Cache::forget($this->key.':fails');

        return $seconds;
    }
}
