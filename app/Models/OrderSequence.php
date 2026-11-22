<?php

namespace App\Models;

use App\Casts\DateOnly;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Nomor antrean harian (#1, #2, …), mulai dari 1 setiap tanggal bisnis. */
#[Fillable(['business_date', 'last_number'])]
class OrderSequence extends Model
{
    protected function casts(): array
    {
        return ['business_date' => DateOnly::class];
    }

    public static function next(CarbonInterface $businessDate): int
    {
        return DB::transaction(function () use ($businessDate) {
            $date = $businessDate->toDateString();
            self::query()->insertOrIgnore(['business_date' => $date, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);

            $row = self::whereDate('business_date', $date)->lockForUpdate()->firstOrFail();
            $row->increment('last_number');

            return $row->last_number;
        });
    }
}
