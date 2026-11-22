<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_nomor_hp_dinormalisasi(): void
    {
        $this->assertSame('081234567890', Customer::normalizePhone('+62 812-3456-7890'));
        $this->assertSame('081234567890', Customer::normalizePhone('6281234567890'));
        $this->assertSame('081234567890', Customer::normalizePhone('0812 3456 7890'));
        $this->assertNull(Customer::normalizePhone(' - '));

        $this->assertSame('081234567890', Customer::create(['name' => 'Ana', 'phone' => '+62 812-3456-7890'])->phone);
    }

    public function test_nomor_hp_unik_setelah_normalisasi(): void
    {
        Customer::create(['name' => 'Ana', 'phone' => '081234567890']);

        $this->expectException(UniqueConstraintViolationException::class);
        Customer::create(['name' => 'Ana lagi', 'phone' => '+6281234567890']);
    }

    public function test_banyak_pelanggan_tanpa_nomor_hp(): void
    {
        Customer::create(['name' => 'Ana']);
        Customer::create(['name' => 'Budi', 'phone' => '']);

        $this->assertSame(2, Customer::whereNull('phone')->count());
    }

    public function test_hapus_data_atas_permintaan_tanpa_menghapus_transaksi(): void
    {
        config(['admin.email' => 'owner@bangkucoffee.id']);
        $this->seed(DemoSeeder::class);
        $customer = Customer::has('orders')->firstOrFail();
        $orderIds = $customer->orders()->pluck('id');
        $orderCount = Order::count();
        $owner = User::firstWhere('name', 'Sinta');

        $customer->erase($owner);

        $this->assertModelMissing($customer);
        $this->assertSame($orderCount, Order::count());
        $this->assertSame(0, Order::whereIn('id', $orderIds)->whereNotNull('customer_name')->count());
        $this->assertSame(0, Order::whereIn('id', $orderIds)->whereNotNull('customer_id')->count());
        $this->assertTrue(Order::whereIn('id', $orderIds)->where('status', OrderStatus::Paid)->exists());

        $log = AuditLog::firstWhere('event', 'customer.erased');
        $this->assertSame($owner->id, $log->user_id);
        $this->assertStringNotContainsString($customer->name, json_encode($log->new_values));
        $this->assertStringNotContainsString((string) $customer->phone, json_encode($log->new_values));
    }
}
