<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Pesan bawaan Laravel (validasi, masuk, halaman) tampil dalam Bahasa Indonesia. */
class LocalizationTest extends TestCase
{
    public static function files(): array
    {
        return ['validation' => ['validation'], 'auth' => ['auth'], 'passwords' => ['passwords'], 'pagination' => ['pagination']];
    }

    /** Semua kunci bawaan Laravel ada di lang/id dengan placeholder yang sama (cek ulang setelah update Laravel). */
    #[DataProvider('files')]
    public function test_semua_kunci_bawaan_diterjemahkan(string $file): void
    {
        $en = Arr::except(require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$file}.php"), ['custom', 'attributes']);
        $id = Arr::dot(require lang_path("id/{$file}.php"));

        foreach (Arr::dot($en) as $key => $text) {
            $this->assertArrayHasKey($key, $id, "lang/id/{$file}.php belum punya kunci {$key}");
            $placeholders = fn (string $s) => collect(preg_match_all('/:([a-z_]+)/i', $s, $m) ? $m[1] : [])->map(fn ($p) => strtolower($p))->unique()->sort()->values()->all();
            $this->assertSame($placeholders($text), $placeholders($id[$key]), "Placeholder {$file}.{$key} berbeda");
        }
    }

    public function test_pesan_validasi_bahasa_indonesia(): void
    {
        $this->assertSame('id', app()->getLocale());

        $errors = Validator::make(['email' => 'bukan-email', 'name' => str_repeat('x', 30)], ['email' => 'email', 'name' => 'max:24', 'phone' => 'required'])->errors();

        $this->assertSame('Email harus berupa alamat email yang valid.', $errors->first('email'));
        $this->assertSame('Nama tidak boleh lebih dari 24 karakter.', $errors->first('name'));
        $this->assertSame('No. HP wajib diisi.', $errors->first('phone'));
    }
}
