<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PortionStandard extends Model
{
    protected $fillable = [
        'sppg_unit_id',
        'beneficiary_category_id',
        'meal_type',
        'item_type',
        'minimum_grams',
        'target_grams',
        'maximum_grams',
        'effective_from',
        'effective_until',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'minimum_grams' => 'decimal:2',
            'target_grams' => 'decimal:2',
            'maximum_grams' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $standard): void {
            $minimum = $standard->minimum_grams !== null
                ? (float) $standard->minimum_grams
                : null;
            $target = (float) $standard->target_grams;
            $maximum = $standard->maximum_grams !== null
                ? (float) $standard->maximum_grams
                : null;

            if ($target <= 0) {
                throw ValidationException::withMessages([
                    'target_grams' => 'Target porsi harus lebih dari nol.',
                ]);
            }

            if ($minimum !== null && $target < $minimum) {
                throw ValidationException::withMessages([
                    'target_grams' => 'Target porsi tidak boleh lebih kecil dari batas minimum.',
                ]);
            }

            if ($maximum !== null && $target > $maximum) {
                throw ValidationException::withMessages([
                    'target_grams' => 'Target porsi tidak boleh melebihi batas maksimum.',
                ]);
            }

            if (
                $standard->effective_from &&
                $standard->effective_until &&
                $standard->effective_until->lt($standard->effective_from)
            ) {
                throw ValidationException::withMessages([
                    'effective_until' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.',
                ]);
            }
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function beneficiaryCategory(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryCategory::class);
    }
}
