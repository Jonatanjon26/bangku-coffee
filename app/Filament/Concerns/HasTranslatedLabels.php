<?php

namespace App\Filament\Concerns;

use UnitEnum;

/**
 * Label resource dari lang/{locale}/admin.php: admin.{langKey}.label|plural.
 * Resource memakai static $langKey dan (opsional) static $navigationGroupKey.
 */
trait HasTranslatedLabels
{
    public static function getModelLabel(): string
    {
        return __('admin.'.static::$langKey.'.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.'.static::$langKey.'.plural');
    }

    /** Judul memakai huruf seperti di terjemahan ("Shift & kas"), bukan Huruf Kapital Tiap Kata. */
    public static function hasTitleCaseModelLabel(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return isset(static::$navigationGroupKey) ? __('admin.groups.'.static::$navigationGroupKey) : null;
    }
}
