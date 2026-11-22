<?php

namespace Tests\Unit;

use App\Support\Printing\EscPos;
use App\Support\Printing\TextLayout;
use PHPUnit\Framework\TestCase;

class TextLayoutTest extends TestCase
{
    public function test_baris_kiri_kanan_tepat_lebar_kolom(): void
    {
        $t = new TextLayout(32);

        $this->assertSame(['Subtotal                  27.000'], $t->row('Subtotal', '27.000'));
        $this->assertSame(32, strlen($t->row('Subtotal', '27.000')[0]));
    }

    public function test_teks_panjang_dibungkus_tanpa_melebihi_kolom(): void
    {
        $t = new TextLayout(32);
        $lines = $t->row('Roti Bakar Cokelat Keju Spesial Ekstra Keju Mozarella', '18.000');

        $this->assertGreaterThan(1, count($lines));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(32, strlen($line));
        }
        $this->assertStringEndsWith('18.000', end($lines));
    }

    public function test_kata_lebih_panjang_dari_kolom_dipotong(): void
    {
        $lines = (new TextLayout(24))->wrap(str_repeat('A', 60));

        $this->assertSame([24, 24, 12], array_map('strlen', $lines));
    }

    public function test_tengah_dan_ascii(): void
    {
        $t = new TextLayout(32);

        $this->assertSame(str_repeat(' ', 11).'Kafe Cafe', $t->center('Kafé Café')[0]);
        $this->assertSame('Creme brulee', TextLayout::ascii('Crème brûlée'));
        $this->assertSame('', TextLayout::ascii('☕'));
    }

    public function test_escpos_reset_gaya_dan_ruang_sobek(): void
    {
        $bytes = EscPos::encode([
            ['cols' => 32, 'lines' => [['text' => 'TIKET'], ['text' => '#7', 'style' => 'big'], ['text' => 'TOTAL', 'style' => 'b']]],
            ['cols' => 32, 'lines' => [['text' => 'Struk']]],
        ]);

        $this->assertStringStartsWith("\x1b@\x1bt\x00", $bytes);
        $this->assertStringContainsString("\x1ba\x01\x1d!\x11\x1bE\x01#7\n\x1d!\x00\x1bE\x00\x1ba\x00", $bytes);
        $this->assertStringContainsString("\x1bE\x01TOTAL\n\x1bE\x00", $bytes);
        $this->assertStringContainsString("\x1bd\x05Struk\n\x1bd\x04", $bytes);
        $this->assertDoesNotMatchRegularExpression('/[^\x00-\x7f]/', $bytes);
    }
}
