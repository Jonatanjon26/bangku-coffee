<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->date('business_date')->index();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();

            // Buka shift & serah terima.
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->unsignedBigInteger('opening_float');
            $table->foreignId('previous_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->unsignedBigInteger('handover_expected')->nullable();
            $table->text('handover_note')->nullable();

            // Tutup shift (hitung buta). closed_at null = shift berjalan.
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('counted_cash')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->foreignId('variance_reason_id')->nullable()->constrained('reasons')->nullOnDelete();
            $table->text('variance_note')->nullable();
            $table->foreignId('variance_approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Modal ditinggal & setoran ke owner.
            $table->unsignedBigInteger('float_left')->nullable();
            $table->unsignedBigInteger('deposit_amount')->nullable();
            $table->foreignId('deposit_received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deposit_received_at')->nullable();

            // Open bill yang dialihkan ke shift berikutnya.
            $table->unsignedInteger('carried_open_bills_count')->default(0);
            $table->unsignedBigInteger('carried_open_bills_total')->default(0);
            $table->foreignId('open_bills_approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_day_close')->default(false);
            $table->json('summary')->nullable(); // ringkasan saat ditutup (laporan 57 mm)
            $table->timestamps();
        });

        // denomination 0 = koin (total).
        Schema::create('shift_cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->string('stage'); // App\Enums\CashCountStage
            $table->unsignedBigInteger('denomination');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('amount');
            $table->timestamps();

            $table->unique(['shift_id', 'stage', 'denomination']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->string('type'); // App\Enums\CashMovementType
            $table->unsignedBigInteger('amount');
            $table->foreignId('reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason_label');
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('shift_cash_counts');
        Schema::dropIfExists('shifts');
    }
};
