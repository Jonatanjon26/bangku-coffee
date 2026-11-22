<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Poin yang didapat pelanggan dari pesanan ini (dikurangi lagi bila direfund, walau aturan poin berubah).
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('points_earned')->default(0)->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('points_earned');
        });
    }
};
