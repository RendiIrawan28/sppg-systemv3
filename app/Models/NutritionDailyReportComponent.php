<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NutritionDailyReportComponent extends Model
{
    protected $fillable = [
        'nutrition_daily_report_id',
        'nutrition_component_id',
        'component_name_snapshot',
        'unit_snapshot',
        'planned_per_portion',
        'actual_per_portion',
        'target_per_portion',
        'achievement_percent',
        'planned_total',
        'actual_total',
    ];

    protected function casts(): array
    {
        return [
            'planned_per_portion' => 'decimal:4',
            'actual_per_portion' => 'decimal:4',
            'target_per_portion' => 'decimal:4',
            'achievement_percent' => 'decimal:2',
            'planned_total' => 'decimal:4',
            'actual_total' => 'decimal:4',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(
            NutritionDailyReport::class,
            'nutrition_daily_report_id'
        );
    }

    public function nutritionComponent(): BelongsTo
    {
        return $this->belongsTo(NutritionComponent::class);
    }
}
