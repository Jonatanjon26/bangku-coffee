<?php

namespace App\Support\Printing;

use Illuminate\Support\Str;

/**
 * Tata letak teks monospace untuk printer termal (mis. 32 kolom pada kertas 58 mm).
 * Teks diubah ke ASCII (printer murah tidak punya font beraksen).
 */
final class TextLayout
{
    public function __construct(public readonly int $cols) {}

    public static function ascii(string $text): string
    {
        return preg_replace('/[^\x20-\x7e]/', '', Str::ascii($text));
    }

    /** @return list<string> */
    public function wrap(string $text, ?int $width = null): array
    {
        $width ??= $this->cols;
        preg_match('/^ */', $text, $m);
        $indent = $m[0];
        $avail = max(1, $width - strlen($indent));
        $out = [];
        $line = $indent;

        foreach (preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            while (strlen($word) > $avail) {
                if (trim($line) !== '') {
                    $out[] = $line;
                    $line = $indent;
                }

                $out[] = $indent.substr($word, 0, $avail);
                $word = substr($word, $avail);
            }

            if ($word === '') {
                continue;
            }

            $candidate = $line.(trim($line) !== '' ? ' ' : '').$word;

            if (strlen($candidate) > $width) {
                $out[] = $line;
                $line = $indent.$word;
            } else {
                $line = $candidate;
            }
        }

        if (trim($line) !== '') {
            $out[] = $line;
        }

        return $out ?: [$indent];
    }

    /** Teks kiri & kanan dalam satu baris (kiri dibungkus bila panjang). @return list<string> */
    public function row(string $left, string $right = ''): array
    {
        $left = self::ascii($left);
        $right = self::ascii($right);
        $lines = $this->wrap($left, $right !== '' ? $this->cols - strlen($right) - 1 : $this->cols);
        $last = array_key_last($lines);
        $lines[$last] = str_pad($lines[$last], $this->cols - strlen($right)).$right;

        return $lines;
    }

    /** @return list<string> */
    public function center(string $text, ?int $width = null): array
    {
        $width ??= $this->cols;

        return array_map(fn (string $l) => str_repeat(' ', intdiv($width - strlen($l), 2)).$l, $this->wrap(self::ascii($text), $width));
    }

    public function rule(): string
    {
        return str_repeat('-', $this->cols);
    }
}
