<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuNutritionSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_id',
        'beneficiary_category_id',
        'nutrition_component_id',
        'value_per_portion',
        'standard_target',
        'achievement_percent',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'value_per_portion' => 'decimal:4',
            'standard_target' => 'decimal:4',
            'achievement_percent' => 'decimal:2',
            'calculated_at' => 'datetime',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            BeneficiaryCategory::class,
            'beneficiary_category_id'
        );
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(
            NutritionComponent::class,
            'nutrition_component_id'
        );
    }
}
