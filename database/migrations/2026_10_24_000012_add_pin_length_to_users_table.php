<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PIN disimpan sebagai hash; panjangnya dicatat supaya pengaturan "panjang PIN" bisa
        // menolak perubahan selama masih ada kasir dengan PIN berbeda panjang (seperti mockup).
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('pin_length')->nullable()->after('pin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin_length');
        });
    }
};
