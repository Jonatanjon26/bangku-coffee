<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Salinan "pilihan bawaan" saat dijual: tiket/struk/cetak ulang hanya menulis pilihan yang bukan
        // bawaan (kecuali grup "selalu cetak"), sama dengan saat transaksi walau pengaturan varian berubah.
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('price_delta');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
