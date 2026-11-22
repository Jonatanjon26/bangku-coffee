<?php

namespace App\Enums;

/**
 * Label enum diambil dari file terjemahan lang/{locale}/pos.php → enums.{nama}.{nilai}.
 */
trait HasLabel
{
    public function label(): string
    {
        return __('pos.enums.'.static::translationKey().'.'.$this->value);
    }

    /** @return array<string, string> nilai => label, untuk pilihan di form */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }

    abstract protected static function translationKey(): string;
}
