<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /** Halaman utama = layar masuk kasir tablet. */
    public function test_halaman_utama_diarahkan_ke_layar_masuk_kasir(): void
    {
        $this->get('/')->assertRedirect('/masuk');
        $this->get('/masuk')->assertOk();
    }
}
