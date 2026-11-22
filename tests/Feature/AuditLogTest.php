<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\ConfigSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_menolak_ubah(): void
    {
        $log = AuditLog::record('order.voided');

        $this->expectException(LogicException::class);
        $log->update(['event' => 'lain']);
    }

    public function test_model_menolak_hapus(): void
    {
        $log = AuditLog::record('order.voided');

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_database_menolak_update_langsung(): void
    {
        AuditLog::record('order.refunded');

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->update(['event' => 'lain']);
    }

    public function test_database_menolak_delete_langsung(): void
    {
        AuditLog::record('order.refunded');

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->delete();
    }

    public function test_mencatat_pengguna_yang_masuk(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $log = AuditLog::record('cash.out', $user, null, ['amount' => 150000], approvedBy: $user);

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->id, $log->approved_by);
        $this->assertTrue($log->auditable->is($user));
        $this->assertSame(['amount' => 150000], $log->fresh()->new_values);
    }

    public function test_perubahan_harga_menu_dan_varian_dicatat(): void
    {
        $this->seed(ConfigSeeder::class);
        $item = MenuItem::firstWhere('name', 'Americano');
        $option = ModifierOption::firstWhere('name', 'Large');

        $item->update(['price' => 21000]);
        $item->update(['name' => 'Americano Panas']); // bukan harga → tidak dicatat
        $option->update(['price_delta' => 6000]);

        $this->assertSame(1, AuditLog::where('event', 'menu_item.price_changed')->count());
        $log = AuditLog::firstWhere('event', 'menu_item.price_changed');
        $this->assertSame(['price' => 20000], $log->old_values);
        $this->assertSame(['price' => 21000], $log->new_values);
        $this->assertSame(['price_delta' => 6000], AuditLog::firstWhere('event', 'modifier_option.price_changed')->new_values);
    }

    public function test_menu_kategori_dan_varian_tercatat_saat_dibuat_dan_dihapus(): void
    {
        $this->seed(DatabaseSeeder::class);
        $category = Category::create(['name' => 'Uji Kategori', 'station_id' => Station::first()->id]);
        $item = MenuItem::create(['category_id' => $category->id, 'name' => 'Kopi Rp 1', 'price' => 1]);
        $group = ModifierGroup::create(['name' => 'Uji Grup']);
        $option = $group->options()->create(['name' => 'Extra shot', 'price_delta' => 50000]);

        $item->delete();
        $option->delete();

        foreach (['category.created', 'menu_item.created', 'modifier_group.created', 'modifier_option.created', 'menu_item.deleted', 'modifier_option.deleted'] as $event) {
            $this->assertTrue(AuditLog::where('event', $event)->exists(), $event);
        }
        $this->assertSame(1, AuditLog::where('event', 'menu_item.created')->latest('id')->first()->new_values['price']);
    }
}
