<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log audit hanya-tambah: tanpa updated_at; UPDATE/DELETE ditolak trigger (lapis kedua setelah model).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event')->index();
            $table->nullableMorphs('auditable');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        $message = 'audit_logs is append-only';

        match (DB::getDriverName()) {
            'sqlite' => collect(['UPDATE', 'DELETE'])->each(fn (string $op) => DB::unprepared(
                'CREATE TRIGGER audit_logs_no_'.strtolower($op)." BEFORE {$op} ON audit_logs BEGIN SELECT RAISE(ABORT, '{$message}'); END;"
            )),
            'mysql', 'mariadb' => collect(['UPDATE', 'DELETE'])->each(fn (string $op) => DB::unprepared(
                'CREATE TRIGGER audit_logs_no_'.strtolower($op)." BEFORE {$op} ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'"
            )),
            // OR REPLACE: fungsi tidak ikut terhapus saat tabel di-drop (migrate:fresh). TRUNCATE melewati trigger
            // per baris, jadi dikunci dengan trigger per pernyataan.
            'pgsql' => DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION audit_logs_append_only() RETURNS trigger AS \$\$
                BEGIN RAISE EXCEPTION '{$message}'; END;
                \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER audit_logs_no_update BEFORE UPDATE OR DELETE ON audit_logs
                    FOR EACH ROW EXECUTE FUNCTION audit_logs_append_only();
                CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs
                    FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_append_only();
                SQL),
            default => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'sqlite', 'mysql', 'mariadb' => collect(['update', 'delete'])->each(fn (string $op) => DB::unprepared("DROP TRIGGER IF EXISTS audit_logs_no_{$op}")),
            'pgsql' => DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs; DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs; DROP FUNCTION IF EXISTS audit_logs_append_only();'),
            default => null,
        };

        Schema::dropIfExists('audit_logs');
    }
};
