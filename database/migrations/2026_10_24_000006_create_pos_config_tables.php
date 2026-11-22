<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('needs_table')->default(false);
            $table->boolean('applies_service')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // kind = App\Enums\PaymentKind (cash | edc).
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('kind');
            $table->boolean('requires_bank')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // type = App\Enums\DiscountType (percent | amount).
        Schema::create('discount_presets', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->unsignedBigInteger('value');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['type', 'value']);
        });

        // type = App\Enums\ReasonType. requires_note: alasan seperti "Lainnya" wajib diberi catatan.
        Schema::create('reasons', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('label');
            $table->boolean('requires_note')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['type', 'label']);
        });

        // Pecahan uang kertas yang dihitung per lembar; koin dihitung sebagai total.
        Schema::create('denominations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('value')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_templates');
        Schema::dropIfExists('denominations');
        Schema::dropIfExists('reasons');
        Schema::dropIfExists('discount_presets');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('order_types');
    }
};
