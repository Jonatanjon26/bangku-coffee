<?php

namespace Database\Seeders;

use App\Enums\DiscountType;
use App\Enums\PaymentKind;
use App\Enums\Permission;
use App\Enums\ReasonType;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Denomination;
use App\Models\DiscountPreset;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\OrderType;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Role;
use App\Models\ShiftTemplate;
use App\Models\Station;
use Illuminate\Database\Seeder;

/**
 * Data awal yang sama dengan mockup (docs/mockup/shared/config.js → DEFAULT_CONFIG).
 * Idempoten: baris dicari berdasarkan nama; yang sudah ada (mungkin sudah diubah di admin) tidak ditimpa.
 */
class ConfigSeeder extends Seeder
{
    public function run(): void
    {
        $this->roles();
        $this->menu();
        $this->posConfig();
    }

    private function roles(): void
    {
        $roles = [
            ['Owner', Permission::cases()],
            ['Kepala toko', [Permission::Pos, Permission::Approve, Permission::Admin, Permission::Reports, Permission::Menu, Permission::Customers]],
            ['Kasir', [Permission::Pos]],
            ['Barista', []],
        ];

        foreach ($roles as $sort => [$name, $permissions]) {
            $role = Role::firstOrCreate(['name' => $name], ['sort' => $sort]);

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            }
        }
    }

    private function menu(): void
    {
        $stations = collect(['Bar', 'Dapur'])->mapWithKeys(fn (string $name, int $sort) => [
            $name => Station::firstOrCreate(['name' => $name], ['sort' => $sort]),
        ]);

        $categories = collect([
            'kopi' => ['Kopi', 'Bar'],
            'nonkopi' => ['Non-kopi', 'Bar'],
            'teh' => ['Teh', 'Bar'],
            'makanan' => ['Makanan', 'Dapur'],
            'camilan' => ['Camilan', 'Dapur'],
        ])->map(fn (array $c, string $key) => Category::firstOrCreate(
            ['name' => $c[0]],
            ['station_id' => $stations[$c[1]]->id, 'sort' => array_search($key, ['kopi', 'nonkopi', 'teh', 'makanan', 'camilan'], true)],
        ));

        // Pilihan pertama = bawaan.
        $groups = collect([
            'size' => ['Ukuran', false, [['Regular', 0], ['Large', 5000]]],
            'temp' => ['Suhu', true, [['Es', 0], ['Panas', 0]]],
            'sugar' => ['Gula', false, [['Normal', 0], ['Kurang manis', 0], ['Tanpa gula', 0]]],
            'shot' => ['Tambahan', false, [['Tanpa', 0], ['Extra shot', 5000], ['Oat milk', 6000]]],
        ])->values()->mapWithKeys(function (array $g, int $sort) {
            [$name, $printAlways, $options] = $g;
            $group = ModifierGroup::firstOrCreate(['name' => $name], ['print_always' => $printAlways, 'sort' => $sort]);

            foreach ($options as $i => [$option, $price]) {
                $group->options()->firstOrCreate(['name' => $option], ['price_delta' => $price, 'is_default' => $i === 0, 'sort' => $i]);
            }

            return [$name => $group];
        });

        $drink = ['Ukuran', 'Suhu', 'Gula', 'Tambahan'];
        $items = [
            ['Kopi Susu Bangku', 'kopi', 22000, '☕', $drink, false, true],
            ['Es Kopi Aren', 'kopi', 23000, '🧋', $drink, false, false],
            ['Americano', 'kopi', 20000, '☕', $drink, false, false],
            ['Cappuccino', 'kopi', 25000, '☕', $drink, false, false],
            ['Caffe Latte', 'kopi', 25000, '🥛', $drink, false, false],
            ['Kopi Tubruk', 'kopi', 15000, '🫖', ['Gula'], false, false],
            ['V60 Manual Brew', 'kopi', 28000, '⚗️', ['Suhu'], false, false],
            ['Matcha Latte', 'nonkopi', 27000, '🍵', ['Ukuran', 'Suhu', 'Gula'], false, false],
            ['Cokelat Bangku', 'nonkopi', 24000, '🍫', ['Ukuran', 'Suhu', 'Gula'], false, false],
            ['Red Velvet', 'nonkopi', 24000, '🥤', ['Ukuran', 'Suhu', 'Gula'], false, false],
            ['Teh Tarik', 'teh', 18000, '🫖', ['Ukuran', 'Suhu', 'Gula'], false, false],
            ['Lemon Tea', 'teh', 16000, '🍋', ['Ukuran', 'Suhu', 'Gula'], false, false],
            ['Croissant Butter', 'makanan', 20000, '🥐', [], false, false],
            ['Roti Bakar Cokelat Keju', 'makanan', 18000, '🍞', [], false, false],
            ['Nasi Goreng Bangku', 'makanan', 28000, '🍛', [], true, false],
            ['Kentang Goreng', 'camilan', 20000, '🍟', [], false, false],
            ['Pisang Goreng', 'camilan', 15000, '🍌', [], false, false],
            ['Air Mineral', 'camilan', 8000, '💧', [], false, false],
        ];

        foreach ($items as $sort => [$name, $category, $price, $icon, $itemGroups, $soldOut, $best]) {
            $item = MenuItem::withTrashed()->firstOrCreate(['name' => $name], [
                'category_id' => $categories[$category]->id,
                'price' => $price,
                'icon' => $icon,
                'is_active' => true,
                'is_sold_out' => $soldOut,
                'is_best_seller' => $best,
                'sort' => $sort,
            ]);

            if ($item->wasRecentlyCreated) {
                $item->modifierGroups()->sync(collect($itemGroups)->mapWithKeys(fn (string $g, int $i) => [$groups[$g]->id => ['sort' => $i]])->all());
            }
        }
    }

    private function posConfig(): void
    {
        $sorted = fn (string $model, array $rows, string $key = 'name') => collect($rows)->each(
            fn (array $row, int $sort) => $model::firstOrCreate([$key => $row[$key]], $row + ['sort' => $sort])
        );

        $sorted(OrderType::class, [
            ['name' => 'Di tempat', 'needs_table' => true, 'applies_service' => true, 'is_active' => true],
            ['name' => 'Bawa pulang', 'needs_table' => false, 'applies_service' => false, 'is_active' => true],
            ['name' => 'Ojek online', 'needs_table' => false, 'applies_service' => false, 'is_active' => false],
        ]);

        $sorted(PaymentMethod::class, [
            ['name' => 'Tunai', 'kind' => PaymentKind::Cash, 'requires_bank' => false, 'is_active' => true],
            ['name' => 'QRIS', 'kind' => PaymentKind::Edc, 'requires_bank' => false, 'is_active' => true],
            ['name' => 'Kartu kredit', 'kind' => PaymentKind::Edc, 'requires_bank' => true, 'is_active' => false],
        ]);

        $sorted(Bank::class, array_map(fn (string $name) => ['name' => $name], ['BCA', 'Mandiri', 'BRI', 'BNI', 'BSI', 'CIMB', 'Permata', 'Lainnya']));

        collect([[DiscountType::Percent, 5], [DiscountType::Percent, 10], [DiscountType::Percent, 20], [DiscountType::Amount, 5000], [DiscountType::Amount, 10000]])
            ->each(fn (array $d, int $sort) => DiscountPreset::firstOrCreate(['type' => $d[0], 'value' => $d[1]], ['sort' => $sort]));

        $sorted(ShiftTemplate::class, [
            ['name' => 'Pagi', 'starts_at' => '07:00', 'ends_at' => '15:00'],
            ['name' => 'Sore', 'starts_at' => '15:00', 'ends_at' => '22:00'],
        ]);

        collect([100000, 50000, 20000, 10000, 5000, 2000, 1000])
            ->each(fn (int $value, int $sort) => Denomination::firstOrCreate(['value' => $value], ['sort' => $sort]));

        $reasons = [
            ReasonType::CashOut->value => ['Es batu', 'Galon', 'Susu', 'Gas', 'Cup/plastik', 'Lainnya'],
            ReasonType::CashIn->value => ['Tambah uang receh', 'Titipan owner', 'Lainnya'],
            ReasonType::Refund->value => ['Salah input', 'Pelanggan batal', 'Item habis', 'Komplain'],
            ReasonType::Void->value => ['Pelanggan batal', 'Tidak dibayar / pelanggan pergi', 'Salah input', 'Digabung ke pesanan lain'],
        ];

        foreach ($reasons as $type => $labels) {
            foreach ($labels as $sort => $label) {
                Reason::firstOrCreate(['type' => $type, 'label' => $label], ['sort' => $sort, 'requires_note' => $label === 'Lainnya']);
            }
        }
    }
}
