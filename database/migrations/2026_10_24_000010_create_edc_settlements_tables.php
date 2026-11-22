<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pencocokan settlement EDC sekali sehari (saat tutup hari).
        Schema::create('edc_settlements', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable(); // wajib bila ada selisih
            $table->foreignId('settled_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('edc_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edc_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('label'); // mis. "Debit BCA"
            $table->unsignedBigInteger('pos_amount');
            $table->unsignedBigInteger('edc_amount');
            $table->bigInteger('difference');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edc_settlement_lines');
        Schema::dropIfExists('edc_settlements');
    }
};
