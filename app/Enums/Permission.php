<?php

namespace App\Enums;

/**
 * Hak akses yang dikenali sistem (tetap di kode). Peran & pembagian haknya diatur di admin.
 */
enum Permission: string
{
    use HasLabel;

    case Pos = 'pos';
    case Approve = 'approve';
    case Admin = 'admin';
    case Reports = 'reports';
    case Menu = 'menu';
    case Customers = 'customers';
    case Staff = 'staff';
    case Settings = 'settings';

    protected static function translationKey(): string
    {
        return 'permission';
    }
}
