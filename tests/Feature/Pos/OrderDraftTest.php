<?php

namespace Tests\Feature\Pos;

use App\Enums\DiscountType;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\OrderType;
use App\Support\OrderDraft;
use Illuminate\Validation\ValidationException;

class OrderDraftTest extends PosTestCase
{
    private function draft(): OrderDraft
    {
        return app(OrderDraft::class);
    }

    private function item(string $name): MenuItem
    {
        return MenuItem::firstWhere('name', $name);
    }

    private function option(string $group, string $name): ModifierOption
    {
        return ModifierGroup::firstWhere('name', $group)->options()->where('name', $name)->first();
    }

    private function state(string $type = 'Di tempat'): array
    {
        return OrderDraft::blank(OrderType::firstWhere('name', $type)->id);
    }

    public function test_pilihan_kosong_memakai_bawaan_dan_harga_dari_database(): void
    {
        $line = $this->draft()->line($this->item('Kopi Susu Bangku')->id, [], 1, '');
        $priced = $this->draft()->price($this->draft()->add($this->state(), $line));

        $this->assertCount(4, $line['picks']);
        $this->assertSame(22000, $priced['lines'][0]['unit']);
        // Bawaan tidak ditulis, kecuali grup "selalu cetak" (Suhu → Es).
        $this->assertSame(['Es'], $priced['lines'][0]['labels']);
    }

    public function test_tambahan_harga_varian_dan_catatan(): void
    {
        $item = $this->item('Kopi Susu Bangku');
        $size = ModifierGroup::firstWhere('name', 'Ukuran');
        $shot = ModifierGroup::firstWhere('name', 'Tambahan');
        $line = $this->draft()->line($item->id, [$size->id => $this->option('Ukuran', 'Large')->id, $shot->id => $this->option('Tambahan', 'Oat milk')->id], 2, '  es sedikit ');
        $priced = $this->draft()->price($this->draft()->add($this->state(), $line));

        $this->assertSame(22000 + 5000 + 6000, $priced['lines'][0]['unit']);
        $this->assertSame(66000, $priced['lines'][0]['total']);
        $this->assertSame(['Large', 'Es', 'Oat milk', '"es sedikit"'], $priced['lines'][0]['labels']);
        $this->assertSame(66000, $priced['totals']->total);
    }

    public function test_baris_sama_digabung(): void
    {
        $id = $this->item('Americano')->id;
        $state = $this->draft()->add($this->state(), $this->draft()->line($id, [], 1, ''));
        $state = $this->draft()->add($state, $this->draft()->line($id, [], 2, ''));
        $state = $this->draft()->add($state, $this->draft()->line($id, [], 1, 'panas sekali'));

        $this->assertCount(2, $state['lines']);
        $this->assertSame(3, $state['lines'][0]['qty']);
    }

    public function test_menu_habis_nonaktif_atau_dihapus_ditolak(): void
    {
        $this->expectValidation(fn () => $this->draft()->line($this->item('Nasi Goreng Bangku')->id, [], 1, ''));

        $hidden = $this->item('Lemon Tea');
        $hidden->update(['is_active' => false]);
        $this->expectValidation(fn () => $this->draft()->line($hidden->id, [], 1, ''));

        $deleted = $this->item('Teh Tarik');
        $deleted->delete();
        $this->expectValidation(fn () => $this->draft()->line($deleted->id, [], 1, ''));
    }

    public function test_pilihan_varian_dari_grup_lain_atau_nonaktif_ditolak(): void
    {
        $tubruk = $this->item('Kopi Tubruk'); // hanya grup Gula
        $size = ModifierGroup::firstWhere('name', 'Ukuran');
        $sugar = ModifierGroup::firstWhere('name', 'Gula');

        $this->expectValidation(fn () => $this->draft()->line($tubruk->id, [$size->id => $this->option('Ukuran', 'Large')->id], 1, ''));
        $this->expectValidation(fn () => $this->draft()->line($tubruk->id, [$sugar->id => $this->option('Ukuran', 'Large')->id], 1, ''));

        $less = $this->option('Gula', 'Kurang manis');
        $less->update(['is_active' => false]);
        $this->expectValidation(fn () => $this->draft()->line($tubruk->id, [$sugar->id => $less->id], 1, ''));
    }

    public function test_jumlah_dan_catatan_dibatasi(): void
    {
        $id = $this->item('Americano')->id;

        $this->expectValidation(fn () => $this->draft()->line($id, [], 0, ''));
        $this->expectValidation(fn () => $this->draft()->line($id, [], OrderDraft::MAX_QTY + 1, ''));
        $this->expectValidation(fn () => $this->draft()->line($id, [], 1, str_repeat('x', OrderDraft::NOTE_MAX + 1)));
    }

    public function test_service_hanya_untuk_jenis_pesanan_yang_kena_dan_pajak_dari_pengaturan(): void
    {
        $this->setting('charges.service_enabled', true);
        $this->setting('charges.tax_enabled', true);
        $line = $this->draft()->line($this->item('Cappuccino')->id, [], 4, ''); // 100.000

        $dine = $this->draft()->price($this->draft()->add($this->state('Di tempat'), $line))['totals'];
        $this->assertSame([5000, 10500, 115500], [$dine->service, $dine->tax, $dine->total]);

        $take = $this->draft()->price($this->draft()->add($this->state('Bawa pulang'), $line))['totals'];
        $this->assertSame([0, 10000, 110000], [$take->service, $take->tax, $take->total]);
    }

    public function test_harga_terbaru_dipakai_saat_dihitung(): void
    {
        $item = $this->item('Americano');
        $state = $this->draft()->add($this->state(), $this->draft()->line($item->id, [], 1, ''));
        $item->update(['price' => 21000]);

        $this->assertSame(21000, $this->draft()->price($state)['totals']->total);
    }

    public function test_batas_persetujuan_diskon(): void
    {
        $draft = $this->draft();

        $this->assertFalse($draft->discountNeedsApproval(DiscountType::Percent, 20, 100000));
        $this->assertTrue($draft->discountNeedsApproval(DiscountType::Percent, 21, 100000));
        $this->assertFalse($draft->discountNeedsApproval(DiscountType::Amount, 20000, 100000));
        $this->assertTrue($draft->discountNeedsApproval(DiscountType::Amount, 20001, 100000));
        $this->assertTrue($draft->discountNeedsApproval(DiscountType::Amount, 5000, 0));
        $this->assertFalse($draft->discountNeedsApproval(null, 0, 100000));

        $this->setting('discounts.approval_above_pct', 50);
        $this->assertFalse($draft->discountNeedsApproval(DiscountType::Percent, 30, 100000));
    }

    private function expectValidation(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Seharusnya ditolak');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
