<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Tanggal tanpa jam, selalu disimpan "Y-m-d" (cast "date" bawaan menyimpan "Y-m-d H:i:s" di SQLite,
 * sehingga index unik per tanggal bisa kebobolan oleh format yang berbeda).
 *
 * @implements CastsAttributes<CarbonImmutable, mixed>
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, config('app.timezone'))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, config('app.timezone'));

        return $date->format('Y-m-d');
    }
}
