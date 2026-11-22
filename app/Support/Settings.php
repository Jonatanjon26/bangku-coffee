<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Pengaturan toko dari tabel `settings` (diubah lewat panel admin), dengan bawaan di config/pos.php.
 * Kunci memakai "grup.kunci", mis. settings()->get('policy.pin_length').
 */
class Settings
{
    private const CACHE_KEY = 'pos.settings';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        [$group, $name] = $this->split($key);

        return array_key_exists($name, $this->all()[$group] ?? []) ? $this->all()[$group][$name] : $default;
    }

    /** @return array<string, mixed> semua kunci dalam satu grup */
    public function group(string $group): array
    {
        return $this->all()[$group] ?? [];
    }

    /** @return array<string, array<string, mixed>> bawaan ditimpa nilai dari database */
    public function all(): array
    {
        return $this->loaded ??= array_replace_recursive(
            config('pos.settings', []),
            Cache::remember(self::CACHE_KEY, config('pos.settings_cache_ttl'), fn () => $this->fromDatabase()),
        );
    }

    /** Ubah satu pengaturan; perubahan dicatat di log audit. Hanya kunci yang dikenal (ada bawaannya). */
    public function set(string $key, mixed $value, ?User $by = null): void
    {
        [$group, $name] = $this->split($key);

        if (! Arr::has(config('pos.settings', []), "{$group}.{$name}")) {
            throw new InvalidArgumentException("Pengaturan tidak dikenal: {$key}");
        }

        $old = $this->get($key);

        if ($old === $value) {
            return;
        }

        Setting::updateOrCreate(['group' => $group, 'key' => $name], ['value' => $value]);
        AuditLog::record('setting.updated', null, [$key => $old], [$key => $value], user: $by);
        $this->flush();
    }

    /** Isi tabel dengan bawaan untuk kunci yang belum ada (tidak menimpa). */
    public function seedDefaults(): void
    {
        foreach (config('pos.settings', []) as $group => $values) {
            foreach ($values as $name => $value) {
                Setting::firstOrCreate(['group' => $group, 'key' => $name], ['value' => $value]);
            }
        }

        $this->flush();
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, array<string, mixed>> */
    private function fromDatabase(): array
    {
        $out = [];

        foreach (Setting::all(['group', 'key', 'value']) as $row) {
            $out[$row->group][$row->key] = $row->value;
        }

        return $out;
    }

    /** @return array{0: string, 1: string} */
    private function split(string $key): array
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException("Kunci pengaturan harus \"grup.kunci\": {$key}");
        }

        return $parts;
    }
}
