<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Beneficiary extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id',
        'beneficiaryable_type',
        'beneficiaryable_id',
        'beneficiary_category_id',
        'code',
        'external_id',
        'name',
        'group_name',
        'parent_name',
        'recipient_position',
        'birth_date',
        'address',
        'gender',
        'start_date',
        'end_date',
        'allergy_notes',
        'special_needs',
        'notes',
        'data_source',
        'last_import_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (
            Beneficiary $beneficiary
        ): void {
            if (blank($beneficiary->code)) {
                $beneficiary->code =
                    (string) Str::ulid();
            }
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function beneficiaryable(): MorphTo
    {
        return $this->morphTo();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            BeneficiaryCategory::class,
            'beneficiary_category_id'
        );
    }

    public function lastImport(): BelongsTo
    {
        return $this->belongsTo(
            BeneficiaryImport::class,
            'last_import_id'
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

    public function allergenLinks(): HasMany
    {
        return $this->hasMany(BeneficiaryAllergen::class);
    }
}
