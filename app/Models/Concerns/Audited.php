<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Catat tambah/ubah/hapus data konfigurasi di log audit ({model}.created|updated|deleted).
 * Kolom tersembunyi (PIN, kata sandi, token) hanya ditandai berubah, nilainya tidak disimpan.
 */
trait Audited
{
    public const AUDIT_MASK = '[diubah]';

    protected static function bootAudited(): void
    {
        static::created(fn (Model $model) => $model->writeAudit('created', null, $model->auditValues($model->getAttributes())));

        static::updated(function (Model $model) {
            $changes = $model->auditValues($model->getChanges());

            if ($changes !== []) {
                $model->writeAudit('updated', $model->auditValues(array_intersect_key($model->getOriginal(), $changes)), $changes);
            }
        });

        static::deleted(fn (Model $model) => $model->writeAudit('deleted', $model->auditValues($model->getAttributes()), null));
    }

    /** @param  array<string, mixed>  $values */
    protected function auditValues(array $values): array
    {
        $values = array_diff_key($values, array_flip(['id', 'created_at', 'updated_at', 'remember_token']));

        foreach ($this->getHidden() as $hidden) {
            if (array_key_exists($hidden, $values)) {
                $values[$hidden] = self::AUDIT_MASK;
            }
        }

        return $values;
    }

    protected function writeAudit(string $action, ?array $old, ?array $new): void
    {
        AuditLog::record(Str::snake(class_basename($this)).'.'.$action, $this, $old, $new);
    }
}
