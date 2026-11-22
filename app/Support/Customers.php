<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Database pelanggan (mockup: Bangku.customers). Kasir mencari dengan nama/no. HP atau menyimpan
 * pelanggan baru dari kolom nama; ringkasan kunjungan, belanja & poin diperbarui di server saat bayar/refund.
 */
class Customers
{
    public const NAME_MAX = 24;

    public const NOTES_MAX = 60;

    /** Panjang isian no. HP sebelum dinormalisasi (spasi, tanda hubung, +62). */
    public const PHONE_INPUT_MAX = 20;

    public const SUGGEST_LIMIT = 6;

    public function __construct(private readonly Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('customers.enabled');
    }

    /**
     * Saran untuk kolom nama: minimal 2 huruf. No. HP hanya dicocokkan bila lengkap (sama persis) — pencocokan
     * sebagian bisa dipakai menebak digit yang disamarkan satu per satu.
     */
    public function search(string $query): Collection
    {
        $q = trim($query);

        if (! $this->enabled() || mb_strlen($q) < 2) {
            return collect();
        }

        $phone = (string) Customer::normalizePhone($q);
        $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';

        return Customer::query()
            ->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])
                ->when(self::validPhone($phone), fn ($o) => $o->orWhere('phone', $phone)))
            ->orderByDesc('last_visit_at')->orderBy('name')
            ->limit(self::SUGGEST_LIMIT)->get();
    }

    /**
     * Validasi & simpan data pelanggan (kasir atau admin). No. HP yang sudah terdaftar tidak dibuat ganda.
     *
     * @param  array{name?: ?string, phone?: ?string, notes?: ?string, contact_consent?: bool}  $input
     *
     * @throws ValidationException
     */
    public function validated(array $input, ?Customer $ignore = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $rawPhone = (string) ($input['phone'] ?? '');
        $phone = Customer::normalizePhone($rawPhone);
        $notes = trim((string) ($input['notes'] ?? ''));
        $errors = [];

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            $errors['name'] = __('pos.customers.errors.name', ['max' => self::NAME_MAX]);
        }

        if ($phone !== null && (mb_strlen($rawPhone) > self::PHONE_INPUT_MAX || ! self::validPhone($phone))) {
            $errors['phone'] = __('pos.customers.errors.phone');
        }

        if (mb_strlen($notes) > self::NOTES_MAX) {
            $errors['notes'] = __('validation.max.string', ['attribute' => __('pos.customers.notes'), 'max' => self::NOTES_MAX]);
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['name' => $name, 'phone' => $phone, 'notes' => $notes !== '' ? $notes : null, 'contact_consent' => (bool) ($input['contact_consent'] ?? false)];
    }

    public static function validPhone(string $phone): bool
    {
        return (bool) preg_match('/^0\d{8,13}$/', $phone);
    }

    /**
     * Pelanggan baru dari kasir. No. HP hanya disimpan bila pengaturan "tawarkan simpan no. HP" aktif.
     *
     * @return array{0: Customer, 1: bool} pelanggan, dan true bila no. HP sudah terdaftar (pelanggan lama dipakai)
     *
     * @throws ValidationException
     */
    public function register(array $input, User $by): array
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['customer' => __('pos.customers.errors.disabled')]);
        }

        if (! $this->settings->get('customers.ask_phone')) {
            $input['phone'] = null;
        }

        $data = $this->validated($input);

        return DB::transaction(function () use ($data, $by) {
            $existing = $data['phone'] ? Customer::where('phone', $data['phone'])->lockForUpdate()->first() : null;

            if ($existing) {
                return [$existing, true];
            }

            $customer = Customer::create($data);
            AuditLog::record('customer.created', $customer, null, ['customer_id' => $customer->id], user: $by);

            return [$customer, false];
        });
    }

    /** Pesanan dibayar: kunjungan, belanja & poin (bila program poin aktif). Dipanggil di dalam transaksi bayar. */
    public function recordVisit(Order $order): void
    {
        if (! $order->customer_id) {
            return;
        }

        $customer = Customer::whereKey($order->customer_id)->lockForUpdate()->first();

        if (! $customer) {
            return;
        }

        $pointPer = (int) $this->settings->get('customers.point_per');
        $points = $this->settings->get('customers.loyalty_enabled') && $pointPer > 0 ? intdiv($order->total, $pointPer) : 0;

        $order->forceFill(['points_earned' => $points])->save();
        $customer->forceFill([
            'visits_count' => $customer->visits_count + 1,
            'total_spent' => $customer->total_spent + $order->total,
            'points' => $customer->points + $points,
            'last_visit_at' => $order->paid_at ?? now(),
        ])->save();
    }

    /**
     * Penukaran poin yang diminta di kasir masuk akal? (program poin aktif, pelanggan terhubung, minimal poin,
     * saldo cukup, nilai tidak melebihi tagihan setelah diskon). Saldo dicek ulang dengan kunci saat bayar (redeem).
     *
     * @throws ValidationException
     */
    public function ensureRedeemable(?Customer $customer, int $points, OrderTotals $totals, int $pointsValue): void
    {
        $error = match (true) {
            ! $this->settings->get('customers.loyalty_enabled') || ! $customer => 'points_unavailable',
            $points < (int) $this->settings->get('customers.redeem_min_points') => 'points_min',
            $points > $customer->points => 'points_balance',
            $totals->points < $pointsValue => 'points_exceed',
            default => null,
        };

        if ($error) {
            throw ValidationException::withMessages(['points' => __('pos.points.errors.'.$error, [
                'min' => (int) $this->settings->get('customers.redeem_min_points'), 'balance' => (int) $customer?->points,
            ])]);
        }
    }

    /** Pesanan dibayar dengan tukar poin: saldo dikunci, dicek ulang, lalu dipotong. Dipanggil di dalam transaksi bayar. */
    public function redeem(Order $order, User $by, Device $device): void
    {
        if ($order->points_redeemed <= 0) {
            return;
        }

        $customer = Customer::whereKey($order->customer_id)->lockForUpdate()->first();

        if (! $customer || $customer->points < $order->points_redeemed) {
            throw ValidationException::withMessages(['points' => __('pos.points.errors.points_balance', ['balance' => (int) $customer?->points])]);
        }

        $customer->forceFill(['points' => $customer->points - $order->points_redeemed])->save();
        AuditLog::record('customer.points_redeemed', $order, null, [
            'customer_id' => $customer->id, 'points' => $order->points_redeemed, 'amount' => $order->points_discount,
        ], user: $by, device: $device);
    }

    /** Refund: kunjungan, belanja & poin pesanan itu dikurangi lagi (tidak di bawah nol); poin yang ditukar dikembalikan. */
    public function reverseVisit(Order $order): void
    {
        $customer = $order->customer_id ? Customer::whereKey($order->customer_id)->lockForUpdate()->first() : null;

        if (! $customer) {
            return;
        }

        $customer->forceFill([
            'visits_count' => max(0, $customer->visits_count - 1),
            'total_spent' => max(0, $customer->total_spent - $order->total),
            // Poin yang ditukar dikembalikan dulu, baru poin yang didapat dikurangi (bila poin itu sudah terpakai
            // di transaksi lain, saldo tidak dibulatkan ke 0 lebih awal lalu ditambah lagi).
            'points' => max(0, $customer->points + $order->points_redeemed - $order->points_earned),
        ])->save();
    }

    /** Saran di kolom nama: hanya yang ditampilkan (catatan & poin tampil setelah pelanggan dipilih). */
    public function suggestion(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->maskedPhone(),
            'visits' => $customer->visits_count,
        ];
    }

    /** Batas cari/pilih/simpan pelanggan per kasir per menit & per hari (pos.customer_lookups_per_*). */
    public function allowLookup(User $user): bool
    {
        $day = 'pos-customer-lookup-day:'.$user->id;

        if (RateLimiter::tooManyAttempts($day, (int) config('pos.customer_lookups_per_day'))) {
            return false;
        }

        if (RateLimiter::attempt('pos-customer-lookup:'.$user->id, (int) config('pos.customer_lookups_per_minute'), fn () => true, 60) !== true) {
            return false;
        }

        RateLimiter::hit($day, 86400);

        return true;
    }
}
