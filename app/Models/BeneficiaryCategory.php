<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BeneficiaryCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id',
        'code',
        'name',
        'group_type',
        'education_level',
        'grade_start',
        'grade_end',
        'portion_size',
        'menu_audience',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'grade_start' => 'integer',
            'grade_end' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class);
    }

    public function scopeForUnit(
        Builder $query,
        int $unitId
    ): Builder {
        return $query->where(
            'sppg_unit_id',
            $unitId
        );
    }
    public function nutritionStandards(): HasMany
    {
        return $this->hasMany(
            NutritionStandard::class,
            'beneficiary_category_id'
        );
    }
}
