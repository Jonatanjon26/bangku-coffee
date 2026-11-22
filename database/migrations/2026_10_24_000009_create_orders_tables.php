<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nomor antrean harian (diambil dengan lockForUpdate).
        Schema::create('order_sequences', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        // Satu baris untuk open bill (status open) maupun yang sudah dibayar. Tidak pernah dihapus.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // tetap walau dibayar hari lain
            $table->date('business_date');
            $table->unsignedInteger('queue_number');
            $table->string('status')->index(); // App\Enums\OrderStatus

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->foreignId('order_type_id')->constrained()->restrictOnDelete();
            $table->string('order_type_name');
            $table->string('table_number', 20)->nullable();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('opened_shift_id')->constrained('shifts')->restrictOnDelete();

            // Total dihitung di server (App\Support\OrderTotals).
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->string('discount_type')->nullable(); // App\Enums\DiscountType
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('service_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('service_amount')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);

            // Penjualan dihitung saat dibayar.
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('paid_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->date('paid_business_date')->nullable()->index();
            $table->boolean('receipt_printed')->default(true);

            // Batal open bill (dengan alasan) & persetujuan lewat hari.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('void_reason_id')->nullable()->constrained('reasons')->nullOnDelete();
            $table->string('void_reason_label')->nullable();
            $table->text('void_note')->nullable();
            $table->foreignId('day_carry_approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['business_date', 'queue_number']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('category_name')->nullable();
            $table->string('station_name')->nullable();
            $table->unsignedBigInteger('base_price');
            $table->unsignedBigInteger('unit_price'); // harga + tambahan varian
            $table->unsignedInteger('quantity');
            $table->text('note')->nullable(); // catatan untuk barista
            $table->unsignedInteger('printed_quantity')->default(0); // sisanya dicetak sebagai TAMBAHAN
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('modifier_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name');
            $table->string('option_name');
            $table->unsignedBigInteger('price_delta')->default(0);
            $table->boolean('print_always')->default(false);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('method_name');
            $table->string('method_kind'); // App\Enums\PaymentKind
            $table->foreignId('bank_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('bank_name')->nullable();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('received');
            $table->unsignedBigInteger('change')->default(0);
            $table->string('reference')->nullable(); // no. struk EDC (opsional)
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->date('business_date')->index();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        // Refund tidak menghapus pesanan asli; status pesanan → refunded.
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->string('method_name');
            $table->string('method_kind');
            $table->foreignId('reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason_label');
            $table->text('note')->nullable();
            $table->foreignId('refunded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete(); // laci tempat uang tunai dikembalikan
            $table->date('business_date')->index();
            $table->timestamps();
        });

        // Salinan daftar open bill yang dialihkan saat tutup shift.
        Schema::create('shift_open_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('total');
            $table->timestamps();

            $table->unique(['shift_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_open_bills');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_sequences');
    }
};
