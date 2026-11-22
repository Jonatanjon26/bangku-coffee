<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_memakai_bawaan_bila_belum_ada_di_database(): void
    {
        $settings = app(Settings::class);

        $this->assertSame(4, $settings->get('policy.pin_length'));
        $this->assertSame('PB1', $settings->get('charges.tax_label'));
        $this->assertSame('ada', $settings->get('policy.tidak_ada', 'ada'));
        $this->assertArrayHasKey('footer', $settings->group('store'));
    }

    public function test_nilai_database_menimpa_bawaan(): void
    {
        Setting::create(['group' => 'charges', 'key' => 'tax_rate', 'value' => 11]);

        $this->assertSame(11, app(Settings::class)->get('charges.tax_rate'));
    }

    public function test_mengubah_pengaturan_tersimpan_dan_tercatat_di_audit(): void
    {
        $owner = User::factory()->admin()->create();
        $settings = app(Settings::class);

        $settings->set('policy.cash_out_approval_above', 150000, $owner);

        $this->assertSame(150000, $settings->get('policy.cash_out_approval_above'));
        $this->assertSame(150000, Setting::where(['group' => 'policy', 'key' => 'cash_out_approval_above'])->value('value'));
        $log = AuditLog::firstWhere('event', 'setting.updated');
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame(['policy.cash_out_approval_above' => 100000], $log->old_values);
        $this->assertSame(['policy.cash_out_approval_above' => 150000], $log->new_values);
    }

    public function test_nilai_sama_tidak_dicatat(): void
    {
        app(Settings::class)->set('store.name', config('pos.settings.store.name'));

        $this->assertSame(0, AuditLog::count());
    }

    public function test_kunci_tidak_dikenal_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Settings::class)->set('policy.boleh_semua', true);
    }

    public function test_kunci_tanpa_grup_ditolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(Settings::class)->get('pin_length');
    }

    public function test_isi_bawaan_tidak_menimpa_yang_sudah_diubah(): void
    {
        $settings = app(Settings::class);
        $settings->set('printing.cols', 42);

        $settings->seedDefaults();

        $this->assertSame(42, $settings->get('printing.cols'));
        $this->assertSame(1, Setting::where(['group' => 'printing', 'key' => 'cols'])->count());
    }
}
