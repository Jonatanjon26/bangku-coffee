<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Sakelar Filament sejajar dengan teks labelnya: filament-shadcn mengecilkan sakelar (1.15rem)
 * sehingga margin -my-0.5 bawaan Filament membuatnya naik. Tema proyek menimpa marginnya.
 */
class AdminThemeTest extends TestCase
{
    public function test_sakelar_sejajar_dengan_label(): void
    {
        $root = dirname(__DIR__, 2);
        $theme = file_get_contents($root.'/resources/css/filament/admin/theme.css');
        $shadcn = file_get_contents($root.'/vendor/mehuli/filament-shadcn/resources/css/inputs.css');

        // Rumus di tema mengikuti tinggi sakelar shadcn; bila package mengubahnya, sesuaikan tema.
        $this->assertMatchesRegularExpression('/\.fi-toggle\s*\{[^}]*height:\s*1\.15rem/', $shadcn);
        $this->assertStringContainsString('.fi-fo-field .fi-fo-field-label > .fi-toggle', $theme);
        $this->assertStringContainsString('margin-block: calc((1.25rem - 1.15rem) / 2);', $theme);
    }
}
