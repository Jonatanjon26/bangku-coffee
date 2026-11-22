<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gambar QR statis milik toko (mis. QRIS) yang ditampilkan di dialog bayar kasir.
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('qr_image')->nullable()->after('requires_bank');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('qr_image');
        });
    }
};
