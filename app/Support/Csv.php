<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Unduhan CSV untuk Excel (BOM UTF-8, pemisah titik koma, aman dari injeksi formula). */
class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel membaca UTF-8
            fputcsv($out, $header, ';');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safe(...), $row), ';');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Cegah injeksi formula di Excel/Sheets (teks diawali = + - @ | % TAB CR; daftar OWASP). */
    public static function safe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@|%\t\r]/', $value) ? "'".$value : $value;
    }
}
