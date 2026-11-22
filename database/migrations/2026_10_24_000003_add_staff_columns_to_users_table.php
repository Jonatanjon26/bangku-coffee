<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // users = karyawan. Email & kata sandi hanya untuk yang masuk panel; kasir cukup PIN.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->foreignId('role_id')->nullable()->after('password')->constrained()->restrictOnDelete();
            $table->string('pin')->nullable()->after('role_id'); // hash
            $table->boolean('is_active')->default(true)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['pin', 'is_active']);
        });
    }
};
