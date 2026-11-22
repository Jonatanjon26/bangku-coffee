<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 20)->nullable()->unique(); // dinormalisasi 08…
            $table->text('notes')->nullable(); // alergi / preferensi
            $table->boolean('contact_consent')->default(false);
            $table->unsignedInteger('points')->default(0);
            $table->unsignedInteger('visits_count')->default(0);
            $table->unsignedBigInteger('total_spent')->default(0);
            $table->timestamp('last_visit_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
