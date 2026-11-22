<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Penukaran poin saat bayar: jumlah poin & nilai potongannya (dikembalikan ke pelanggan bila direfund).
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('points_redeemed')->default(0)->after('points_earned');
            $table->unsignedBigInteger('points_discount')->default(0)->after('points_redeemed');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['points_redeemed', 'points_discount']);
        });
    }
};
