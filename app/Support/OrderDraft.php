<?php

namespace App\Support;

use App\Enums\DiscountType;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\OrderType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pesanan yang sedang disusun di kasir (belum disimpan/dibayar). Status hanya berisi id & jumlah;
 * nama, harga, varian, dan total selalu dihitung dari database di server.
 *
 * Bentuk status: ['order_id' => ?int (pesanan tersimpan yang dibuka), 'version' => ?string, 'type_id' => ?int, 'table' => string, 'lines' => [['item_id', 'picks' => [group_id => option_id], 'qty', 'note', 'printed']],
 *                 'discount' => ['type' => ?string, 'value' => int, 'approved_by' => ?int]]
 */
class OrderDraft
{
    public const MAX_QTY = 50;

    public const NOTE_MAX = 40;

    public const TABLE_MAX = 3;

    public function __construct(private readonly Settings $settings) {}

    public static function blank(?int $typeId): array
    {
        return ['draft_id' => (string) Str::uuid(), 'order_id' => null, 'version' => null, 'type_id' => $typeId, 'table' => '', 'lines' => [], 'discount' => ['type' => null, 'value' => 0, 'approved_by' => null], 'voids' => [], 'customer_id' => null, 'points' => 0];
    }

    /**
     * Baris open bill yang sudah dipesan & tercetak (jumlah tidak melebihi yang tercetak): tidak dicek ketersediaan
     * menu/varian sekarang — barangnya sudah dibuat. Hanya jumlah & catatan yang dicek.
     *
     * @return array{item_id: int, picks: array<int, int>, qty: int, note: string}
     *
     * @throws ValidationException
     */
    public function storedLine(array $line): array
    {
        $qty = (int) $line['qty'];
        $note = trim((string) $line['note']);

        if ($qty < 1 || $qty > self::MAX_QTY || mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['qty' => __('pos.order.errors.qty', ['max' => self::MAX_QTY])]);
        }

        $picks = array_map('intval', (array) $line['picks']);
        ksort($picks);

        return ['item_id' => (int) $line['item_id'], 'picks' => $picks, 'qty' => $qty, 'note' => $note];
    }

    /**
     * Validasi satu baris: menu aktif & tidak habis, tepat satu pilihan aktif per grup varian menu itu
     * (yang tidak dipilih = pilihan bawaan), jumlah & catatan wajar.
     *
     * @param  array<int|string, mixed>  $picks
     * @return array{item_id: int, picks: array<int, int>, qty: int, note: string}
     *
     * @throws ValidationException
     */
    public function line(int $itemId, array $picks, int $qty, string $note): array
    {
        $item = MenuItem::onPos()->with('modifierGroups.options')->find($itemId);

        if (! $item || $item->is_sold_out) {
            throw ValidationException::withMessages(['item' => __('pos.order.errors.unavailable')]);
        }

        if ($qty < 1 || $qty > self::MAX_QTY) {
            throw ValidationException::withMessages(['qty' => __('pos.order.errors.qty', ['max' => self::MAX_QTY])]);
        }

        $note = trim($note);

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['note' => __('validation.max.string', ['attribute' => __('pos.order.note'), 'max' => self::NOTE_MAX])]);
        }

        $groupIds = $item->modifierGroups->pluck('id')->all();

        if (array_diff(array_map('intval', array_keys($picks)), $groupIds) !== []) {
            throw ValidationException::withMessages(['picks' => __('pos.order.errors.options')]);
        }

        $clean = [];

        foreach ($item->modifierGroups as $group) {
            $optionId = $picks[$group->id] ?? $picks[(string) $group->id] ?? null;
            $option = $optionId === null
                ? $this->defaultOption($group)
                : $group->options->first(fn (ModifierOption $o) => $o->id === (int) $optionId && $o->is_active);

            if (! $option) {
                throw ValidationException::withMessages(['picks' => __('pos.order.errors.options')]);
            }

            $clean[$group->id] = $option->id;
        }

        ksort($clean);

        return ['item_id' => $item->id, 'picks' => $clean, 'qty' => $qty, 'note' => $note];
    }

    /** Identitas baris untuk mencocokkan item tercetak: menu + varian + catatan. */
    public static function signature(int $itemId, array $picks, string $note): string
    {
        ksort($picks);

        return $itemId.'|'.json_encode(array_map('intval', $picks)).'|'.$note;
    }

    /** Tambah baris; baris yang sama persis (menu, varian, catatan) digabung jumlahnya. */
    public function add(array $state, array $line): array
    {
        foreach ($state['lines'] as $i => $existing) {
            if ($existing['item_id'] === $line['item_id'] && $existing['picks'] == $line['picks'] && $existing['note'] === $line['note']) {
                $state['lines'][$i]['qty'] = min(self::MAX_QTY, $existing['qty'] + $line['qty']);

                return $state;
            }
        }

        $state['lines'][] = $line + ['printed' => 0];

        return $state;
    }

    /**
     * Harga & total dari database.
     *
     * @return array{lines: list<array<string, mixed>>, totals: OrderTotals, count: int, type: ?OrderType, discount_percent: float, needs_approval: bool}
     */
    public function price(array $state): array
    {
        $items = MenuItem::withTrashed()->with(['modifierGroups.options', 'category.station'])->findMany(array_column($state['lines'], 'item_id'))->keyBy('id');
        $lines = [];
        $subtotal = 0;

        foreach ($state['lines'] as $i => $line) {
            $item = $items[$line['item_id']] ?? null;
            $stored = $line['stored'] ?? null;

            if (! $item) {
                continue;
            }

            // Baris open bill yang sudah dipesan memakai salinan saat dipesan (nama, varian, harga),
            // walau menu sekarang habis/nonaktif atau harganya berubah.
            [$unit, $labels] = $stored ? [(int) $stored['unit'], $stored['labels']] : $this->unitPriceAndLabels($item, $line['picks']);
            $subtotal += $unit * $line['qty'];
            $lines[] = [
                'index' => $i,
                'item_id' => $item->id,
                'name' => $stored['name'] ?? $item->name,
                'labels' => array_merge($labels, $line['note'] !== '' ? ['"'.$line['note'].'"'] : []),
                'unit' => $unit,
                'total' => $unit * $line['qty'],
                'qty' => $line['qty'],
                'picks' => $line['picks'],
                'note' => $line['note'],
                'printed' => (int) ($line['printed'] ?? 0),
                'editable' => $item->modifierGroups->isNotEmpty(),
                // Yang sudah dibuat (tercetak) tetap bisa dibayar; tanda hanya bila ada tambahan yang tidak tersedia.
                'unavailable' => (! $stored || $line['qty'] > (int) ($line['printed'] ?? 0)) && (! $item->is_active || $item->is_sold_out || $item->trashed()),
            ];
        }

        $type = $state['type_id'] ? OrderType::find($state['type_id']) : null;
        $serviceRate = $this->settings->get('charges.service_enabled') && $type?->applies_service ? (float) $this->settings->get('charges.service_rate') : 0.0;
        $taxRate = $this->settings->get('charges.tax_enabled') ? (float) $this->settings->get('charges.tax_rate') : 0.0;
        $discountType = DiscountType::tryFrom((string) $state['discount']['type']);
        $totals = OrderTotals::calculate($subtotal, $discountType, (int) $state['discount']['value'], $serviceRate, $taxRate, $this->pointsValue((int) ($state['points'] ?? 0)));

        return [
            'lines' => $lines,
            'totals' => $totals,
            'count' => array_sum(array_column($lines, 'qty')),
            'type' => $type,
            'discount_percent' => $totals->discountPercent(),
            'needs_approval' => $this->discountNeedsApproval($discountType, (int) $state['discount']['value'], $subtotal),
        ];
    }

    /** Diskon (persen dari subtotal) di atas batas pengaturan perlu persetujuan. */
    /** Nilai rupiah poin yang ditukar (0 bila program poin mati). Cukup tidaknya poin pelanggan dicek di Checkout. */
    public function pointsValue(int $points): int
    {
        return $points > 0 && $this->settings->get('customers.loyalty_enabled') ? $points * max(0, (int) $this->settings->get('customers.point_value')) : 0;
    }

    public function discountNeedsApproval(?DiscountType $type, int $value, int $subtotal): bool
    {
        $percent = match ($type) {
            DiscountType::Percent => (float) $value,
            DiscountType::Amount => $subtotal > 0 ? $value * 100 / $subtotal : ($value > 0 ? 100.0 : 0.0),
            null => 0.0,
        };

        return $percent > (float) $this->settings->get('discounts.approval_above_pct');
    }

    /**
     * Harga satuan (harga menu + tambahan varian) & label untuk tiket/struk:
     * pilihan bawaan tidak ditulis, kecuali grup "selalu cetak" (mis. Es/Panas).
     *
     * @return array{0: int, 1: list<string>}
     */
    public function unitPriceAndLabels(MenuItem $item, array $picks): array
    {
        $unit = $item->price;
        $labels = [];

        foreach ($item->modifierGroups as $group) {
            $option = $group->options->firstWhere('id', $picks[$group->id] ?? null) ?? $this->defaultOption($group);

            if (! $option) {
                continue;
            }

            $unit += $option->price_delta;

            if ($option->id !== $this->defaultOption($group)?->id || $group->print_always) {
                $labels[] = $option->name;
            }
        }

        return [$unit, $labels];
    }

    public function defaultOption(ModifierGroup $group): ?ModifierOption
    {
        $active = $group->options->where('is_active', true);

        return $active->firstWhere('is_default', true) ?? $active->sortBy('sort')->first();
    }

    /** Menu untuk layar kasir (kategori aktif, menu tampil di kasir) beserta grup varian. */
    public function menu(): Collection
    {
        return MenuItem::onPos()
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->with(['category.station', 'modifierGroups.options' => fn ($q) => $q->where('is_active', true)->orderBy('sort')])
            ->orderBy('sort')
            ->get();
    }
}
