<?php

namespace Database\Seeders;

use App\Enums\CashCountStage;
use App\Enums\CashMovementType;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\ReasonType;
use App\Models\Bank;
use App\Models\CashMovement;
use App\Models\Customer;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\EdcSettlement;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderSequence;
use App\Models\OrderType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reason;
use App\Models\Refund;
use App\Models\Shift;
use App\Models\ShiftCashCount;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data contoh 14 hari (shift, transaksi, kas keluar, selisih, refund, settlement EDC, 12 pelanggan),
 * sama dengan tombol "Isi data contoh" di admin mockup. Hanya local/testing; deterministik.
 * Jalankan: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DAYS = 14;

    private const FLOAT = 300000;

    private int $seed = 7;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('DemoSeeder hanya untuk lingkungan local.');

            return;
        }

        $this->call([SettingsSeeder::class, ConfigSeeder::class, StaffSeeder::class, DeviceSeeder::class]);

        if (Order::exists()) {
            $this->command?->warn('Sudah ada transaksi — data contoh tidak diisi.');

            return;
        }

        DB::transaction(fn () => $this->seedDemo());
    }

    private function seedDemo(): void
    {
        $settings = app(Settings::class);
        $now = CarbonImmutable::now();
        $names = ['Ana', 'Budi', 'Cici', 'Dedi', 'Eka', 'Fajar', 'Gita', 'Hendra', 'Intan', 'Joko', 'Kiki', 'Lia', 'Maya', 'Nanda', 'Oki', 'Putri', 'Rizky', 'Sari', 'Tono', 'Wulan'];
        $customers = collect(array_slice($names, 0, 12))->map(fn (string $name, int $i) => Customer::create([
            'name' => $name,
            'phone' => '0812'.substr((string) (10000000 + $i * 7919), 0, 8),
            'notes' => $i === 2 ? 'Alergi susu sapi' : null,
            'contact_consent' => $i % 3 !== 0,
        ]));

        $items = MenuItem::with(['category.station', 'modifierGroups.options'])->onPos()->where('is_sold_out', false)->orderBy('sort')->get();
        $methods = PaymentMethod::active()->get();
        $cash = $methods->firstWhere('name', 'Tunai') ?? $methods->first();
        $qris = $methods->firstWhere('name', 'QRIS') ?? $cash;
        $bankMethod = $methods->firstWhere('requires_bank', true) ?? $cash;
        $banks = Bank::active()->take(4)->get();
        $types = OrderType::active()->get();
        $cashiers = User::withPermission(Permission::Pos)->where('is_admin', false)->whereHas('role', fn ($q) => $q->where('name', '!=', 'Owner'))->orderBy('id')->get();
        $owner = User::where('is_admin', true)->first() ?? $cashiers->first();
        $approver = User::withPermission(Permission::Approve)->where('is_admin', false)->first() ?? $owner;
        $device = Device::active()->first();
        $templates = ShiftTemplate::active()->get();
        $denominations = Denomination::active()->orderByDesc('value')->pluck('value');
        $cashOutReasons = Reason::active()->ofType(ReasonType::CashOut)->where('requires_note', false)->get();
        $refundReason = Reason::active()->ofType(ReasonType::Refund)->skip(1)->first();
        $varianceLimit = (int) $settings->get('policy.variance_approval_above');
        $previous = null;

        if ($cashiers->isEmpty() || $items->isEmpty() || $templates->isEmpty()) {
            $this->command?->warn('Karyawan kasir, menu, atau shift belum ada — data contoh tidak diisi.');

            return;
        }

        for ($d = self::DAYS - 1; $d >= 0; $d--) {
            $day = $now->subDays($d)->startOfDay();
            $queue = 0;

            foreach ($templates as $si => $template) {
                if ($d === 0 && $si > 0) {
                    continue;
                }

                [$fh] = array_map('intval', explode(':', $template->starts_at));
                [$th] = array_map('intval', explode(':', $template->ends_at));
                $cashier = $cashiers[($d + $si) % $cashiers->count()];
                $openedAt = $day->addHours($fh)->addMinutes(5);

                if ($openedAt->isAfter($now)) {
                    continue;
                }

                $shift = new Shift([
                    'shift_template_id' => $template->id,
                    'name' => $template->name,
                    'business_date' => $day,
                    'device_id' => $device?->id,
                    'opening_float' => self::FLOAT,
                    'previous_shift_id' => $previous?->id,
                    'handover_expected' => $previous?->float_left,
                ]);
                $shift->forceFill(['opened_by' => $cashier->id, 'opened_at' => $openedAt, 'created_at' => $openedAt, 'updated_at' => $openedAt])->save();
                $this->saveCounts($shift, CashCountStage::Opening, self::FLOAT, $denominations);

                $n = 18 + (int) floor($this->rnd() * 22) + ($day->dayOfWeek % 6 === 0 ? 12 : 0);

                for ($k = 0; $k < $n; $k++) {
                    $at = $day->addSeconds((int) (($fh + $this->rnd() * ($th - $fh - 0.3)) * 3600));

                    if ($at->isAfter($now)) {
                        continue;
                    }

                    $queue++;
                    $r = $this->rnd();
                    $method = $r < .45 ? $cash : ($r < .85 ? $qris : $bankMethod);
                    $customer = $this->rnd() < .3 ? $this->pick($customers) : null;
                    $type = $this->rnd() < .6 ? $types->first() : ($types[1] ?? $types->first());
                    $order = $this->createOrder($day, $queue, $at, $shift, $cashier, $device, $type, $customer, $customer?->name ?? $this->pick(collect($names)), $items);

                    $bank = $method->requires_bank ? $this->pick($banks) : null;
                    $this->pay($order, $method, $bank, $shift, $cashier, $day, $at);

                    if ($k === 3 && $d === 2 && $refundReason) {
                        $this->refund($order, $method, $refundReason, $approver, $shift, $day, $at);
                    } elseif ($customer) {
                        $customer->forceFill([
                            'visits_count' => $customer->visits_count + 1,
                            'total_spent' => $customer->total_spent + $order->total,
                            'last_visit_at' => $at,
                        ])->save();
                    }
                }

                OrderSequence::updateOrCreate(['business_date' => $day->toDateString()], ['last_number' => $queue]);

                if ($cashOutReasons->isNotEmpty()) {
                    $reason = $this->pick($cashOutReasons);
                    $move = new CashMovement(['shift_id' => $shift->id, 'type' => CashMovementType::Out, 'amount' => 20000 + (int) floor($this->rnd() * 3) * 10000, 'reason_id' => $reason->id, 'reason_label' => $reason->label]);
                    $move->forceFill(['user_id' => $cashier->id, 'created_at' => $openedAt->addMinutes(30), 'updated_at' => $openedAt->addMinutes(30)])->save();
                }

                $closedAt = $day->addHours($th);

                if ($closedAt->isAfter($now)) {
                    break; // shift hari ini masih berjalan
                }

                $isDayClose = $si === $templates->count() - 1;
                $this->close($shift, $closedAt, $isDayClose, $denominations, $varianceLimit, $owner);
                $previous = $shift;

                if ($isDayClose) {
                    $this->settle($shift, $day, $cashier);
                }
            }
        }

        $this->command?->info('Data contoh '.self::DAYS.' hari diisi: '.Order::count().' transaksi, '.Shift::count().' shift.');
    }

    private function createOrder(CarbonImmutable $day, int $queue, CarbonImmutable $at, Shift $shift, User $cashier, ?Device $device, OrderType $type, ?Customer $customer, string $name, Collection $items): Order
    {
        $order = new Order([
            'customer_id' => $customer?->id,
            'customer_name' => $name,
            'order_type_id' => $type->id,
            'order_type_name' => $type->name,
            'receipt_printed' => true,
        ]);
        $order->forceFill([
            'code' => Order::makeCode($day, $queue),
            'business_date' => $day,
            'queue_number' => $queue,
            'status' => OrderStatus::Paid,
            'device_id' => $device?->id,
            'created_by' => $cashier->id,
            'opened_shift_id' => $shift->id,
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();

        $lines = 1 + (int) floor($this->rnd() * 3);

        for ($i = 0; $i < $lines; $i++) {
            $menu = $this->pick($items);
            $quantity = $this->rnd() < .2 ? 2 : 1;
            $item = $order->items()->create([
                'menu_item_id' => $menu->id,
                'name' => $menu->name,
                'category_name' => $menu->category->name,
                'station_name' => $menu->category->station->name,
                'base_price' => $menu->price,
                'unit_price' => $menu->price,
                'quantity' => $quantity,
                'printed_quantity' => $quantity,
                'sort' => $i,
            ]);

            // Pilihan bawaan tiap grup varian (tanpa tambahan harga), seperti pesanan tanpa ubahan.
            foreach ($menu->modifierGroups as $group) {
                $option = $group->options->firstWhere('is_default', true) ?? $group->options->first();

                if ($option) {
                    $item->modifiers()->create([
                        'modifier_group_id' => $group->id,
                        'modifier_option_id' => $option->id,
                        'group_name' => $group->name,
                        'option_name' => $option->name,
                        'price_delta' => $option->price_delta,
                        'is_default' => true,
                        'print_always' => $group->print_always,
                    ]);
                }
            }
        }

        $order->recalculate($type);
        $order->forceFill(['paid_at' => $at, 'paid_by' => $cashier->id, 'paid_shift_id' => $shift->id, 'paid_business_date' => $day])->save();

        return $order;
    }

    private function pay(Order $order, PaymentMethod $method, ?Bank $bank, Shift $shift, User $cashier, CarbonImmutable $day, CarbonImmutable $at): void
    {
        Payment::create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'method_name' => $method->name,
            'method_kind' => $method->kind,
            'bank_id' => $bank?->id,
            'bank_name' => $bank?->name,
            'amount' => $order->total,
            'received' => $order->total,
            'change' => 0,
            'shift_id' => $shift->id,
            'business_date' => $day,
            'user_id' => $cashier->id,
            'paid_at' => $at,
        ]);
    }

    private function refund(Order $order, PaymentMethod $method, Reason $reason, User $approver, Shift $shift, CarbonImmutable $day, CarbonImmutable $at): void
    {
        $refund = new Refund([
            'order_id' => $order->id,
            'amount' => $order->total,
            'payment_method_id' => $method->id,
            'method_name' => $method->name,
            'method_kind' => $method->kind,
            'reason_id' => $reason->id,
            'reason_label' => $reason->label,
            'shift_id' => $shift->id,
            'business_date' => $day,
        ]);
        $refund->forceFill(['refunded_by' => $approver->id, 'approved_by' => $approver->id, 'created_at' => $at, 'updated_at' => $at])->save();
        $order->forceFill(['status' => OrderStatus::Refunded])->save();
    }

    private function close(Shift $shift, CarbonImmutable $closedAt, bool $isDayClose, Collection $denominations, int $varianceLimit, User $owner): void
    {
        $summary = $shift->summarize();
        $variance = $this->rnd() < .7 ? 0 : -((int) round($this->rnd() * 6)) * 5000;
        $counted = $summary['expected'] + $variance;
        $this->saveCounts($shift, CashCountStage::Closing, $counted, $denominations);

        $shift->forceFill([
            'closed_by' => $shift->opened_by,
            'closed_at' => $closedAt,
            'expected_cash' => $summary['expected'],
            'counted_cash' => $counted,
            'variance' => $variance,
            'variance_note' => $variance ? 'Salah beri kembalian' : null,
            'variance_approved_by' => abs($variance) > $varianceLimit ? $owner->id : null,
            'float_left' => self::FLOAT,
            'deposit_amount' => $counted - self::FLOAT,
            'is_day_close' => $isDayClose,
            'summary' => $summary,
            'updated_at' => $closedAt,
        ])->save();
    }

    /** Settlement EDC: total POS per metode & bank hari itu (contoh: cocok dengan struk EDC). */
    private function settle(Shift $shift, CarbonImmutable $day, User $by): void
    {
        $settlement = new EdcSettlement(['business_date' => $day, 'shift_id' => $shift->id]);
        $settlement->forceFill(['settled_by' => $by->id])->save();

        Payment::whereDate('business_date', $day->toDateString())
            ->where('method_kind', 'edc')
            ->selectRaw('payment_method_id, bank_id, method_name, bank_name, SUM(amount) as total')
            ->groupBy('payment_method_id', 'bank_id', 'method_name', 'bank_name')
            ->get()
            ->each(fn ($row) => $settlement->lines()->create([
                'payment_method_id' => $row->payment_method_id,
                'bank_id' => $row->bank_id,
                'label' => trim($row->method_name.' '.$row->bank_name),
                'pos_amount' => (int) $row->total,
                'edc_amount' => (int) $row->total,
                'difference' => 0,
            ]));
    }

    /** Pecah jumlah ke pecahan terbesar dulu; sisa (di bawah pecahan terkecil) dicatat sebagai koin. */
    private function saveCounts(Shift $shift, CashCountStage $stage, int $amount, Collection $denominations): void
    {
        $left = max(0, $amount);

        foreach ($denominations as $value) {
            $quantity = intdiv($left, $value);

            if ($quantity > 0) {
                $shift->cashCounts()->create(['stage' => $stage, 'denomination' => $value, 'quantity' => $quantity, 'amount' => $quantity * $value]);
                $left -= $quantity * $value;
            }
        }

        if ($left > 0) {
            $shift->cashCounts()->create(['stage' => $stage, 'denomination' => ShiftCashCount::COINS, 'quantity' => 1, 'amount' => $left]);
        }
    }

    /** Bilangan acak deterministik (sama dengan generator di mockup admin). */
    private function rnd(): float
    {
        $this->seed = ($this->seed * 16807) % 2147483647;

        return ($this->seed - 1) / 2147483646;
    }

    private function pick(Collection $items): mixed
    {
        return $items[(int) floor($this->rnd() * $items->count())];
    }
}
