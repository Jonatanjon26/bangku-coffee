<?php

namespace App\Support\Printing;

/**
 * Perintah ESC/POS untuk printer termal 58 mm. Beberapa dokumen dalam satu kiriman
 * (mis. tiket barista lalu struk) dipisah ruang untuk menyobek.
 */
final class EscPos
{
    /** @param  list<array{cols: int, lines: list<array{text: string, style?: string}>}>  $docs */
    public static function encode(array $docs): string
    {
        $out = "\x1b@\x1bt\x00"; // reset, code page standar

        foreach (array_values($docs) as $i => $doc) {
            foreach ($doc['lines'] as $line) {
                $style = $line['style'] ?? null;
                $text = in_array($style, ['big', 'big-c'], true) ? trim($line['text']) : $line['text'];

                $out .= match ($style) {
                    'big' => "\x1ba\x01\x1d!\x11\x1bE\x01",   // tengah, 2× lebar & tinggi, tebal
                    'big-c' => "\x1ba\x01\x1d!\x01\x1bE\x01", // tengah, 2× tinggi, tebal
                    'b' => "\x1bE\x01",
                    default => '',
                };
                $out .= TextLayout::ascii($text)."\n";
                $out .= match ($style) {
                    'big', 'big-c' => "\x1d!\x00\x1bE\x00\x1ba\x00",
                    'b' => "\x1bE\x00",
                    default => '',
                };
            }

            $out .= "\x1bd".($i < count($docs) - 1 ? "\x05" : "\x04"); // ruang untuk menyobek
        }

        return $out;
    }
}
