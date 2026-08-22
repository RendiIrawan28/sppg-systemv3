<?php

namespace App\Models;

use App\Services\ServiceHolidayImpactService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceHoliday extends Model
{
    protected $fillable = [
        'sppg_unit_id',
        'holiday_date',
        'name',
        'holiday_type',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    protected static function booted(): void
    {
        static::saved(function (self $holiday): void {
            if ($holiday->is_active) {
                app(ServiceHolidayImpactService::class)->reconcileDate(
                    (int) $holiday->sppg_unit_id,
                    $holiday->holiday_date,
                    auth()->id(),
                    (string) $holiday->name,
                );
            }
        });
    }
}
