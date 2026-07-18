<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id',
        'code',
        'npsn',
        'name',
        'education_level',
        'address',
        'village',
        'district',
        'city',
        'province',
        'pic_name',
        'pic_phone',
        'pic_email',
        'latitude',
        'longitude',
        'receiving_time',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function beneficiaries(): MorphMany
    {
        return $this->morphMany(
            Beneficiary::class,
            'beneficiaryable'
        );
    }

    public function beneficiaryImports(): MorphMany
    {
        return $this->morphMany(
            BeneficiaryImport::class,
            'institution'
        );
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
}