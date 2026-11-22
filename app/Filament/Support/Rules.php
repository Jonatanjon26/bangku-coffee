<?php

namespace App\Filament\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/** Aturan validasi bersama untuk form admin. */
final class Rules
{
    /**
     * Untuk toggle "Aktif": tolak bila ini akan membuat tidak ada baris aktif sama sekali.
     *
     * @param  class-string<Model>  $model
     */
    public static function keepOneActive(string $model, string $message): Closure
    {
        return fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($model, $message, $record): void {
            if (! $value && ! $model::query()->where('is_active', true)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                $fail($message);
            }
        };
    }

    /** Rupiah bulat ≥ 0. */
    public static function money(): array
    {
        return ['integer', 'min:0', 'max:999999999'];
    }
}
