<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class NutritionStandard extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id',
        'beneficiary_category_id',
        'nutrition_component_id',
        'age_min_months',
        'age_max_months',
        'minimum_value',
        'target_value',
        'maximum_value',
        'effective_from',
        'effective_until',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'age_min_months' => 'integer',
            'age_max_months' => 'integer',
            'minimum_value' => 'decimal:4',
            'target_value' => 'decimal:4',
            'maximum_value' => 'decimal:4',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(
            function (
                NutritionStandard $standard
            ): void {
                $standard->validateBusinessRules();
            }
        );
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(
            SppgUnit::class
        );
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

    public function scopeForUnit(
        Builder $query,
        int $unitId
    ): Builder {
        return $query->where(
            'sppg_unit_id',
            $unitId
        );
    }

    public function scopeCurrentlyEffective(
        Builder $query,
        CarbonInterface|string|null $date = null
    ): Builder {
        $date = $date
            ? Carbon::parse($date)
            : now();

        return $query
            ->where('is_active', true)
            ->whereDate(
                'effective_from',
                '<=',
                $date
            )
            ->where(
                function (
                    Builder $periodQuery
                ) use ($date): void {
                    $periodQuery
                        ->whereNull(
                            'effective_until'
                        )
                        ->orWhereDate(
                            'effective_until',
                            '>=',
                            $date
                        );
                }
            );
    }

    public function getAgeRangeLabel(): string
    {
        if (
            $this->age_min_months === null &&
            $this->age_max_months === null
        ) {
            return 'Semua usia';
        }

        if ($this->age_min_months === null) {
            return "Maksimal {$this->age_max_months} bulan";
        }

        if ($this->age_max_months === null) {
            return "Minimal {$this->age_min_months} bulan";
        }

        return "{$this->age_min_months}–{$this->age_max_months} bulan";
    }

    public function getEffectivePeriodLabel(): string
    {
        $start = $this->effective_from
            ?->format('d M Y') ?? '-';

        $end = $this->effective_until
            ?->format('d M Y') ?? 'Tanpa batas';

        return "{$start} – {$end}";
    }

    public function getPeriodStatus(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        $today = now()->startOfDay();

        if (
            $this->effective_from &&
            $this->effective_from->startOfDay()
                ->isAfter($today)
        ) {
            return 'upcoming';
        }

        if (
            $this->effective_until &&
            $this->effective_until->endOfDay()
                ->isBefore($today)
        ) {
            return 'expired';
        }

        return 'effective';
    }

    public function getPeriodStatusLabel(): string
    {
        return match (
            $this->getPeriodStatus()
        ) {
            'effective' => 'Sedang Berlaku',
            'upcoming' => 'Belum Berlaku',
            'expired' => 'Kedaluwarsa',
            'inactive' => 'Tidak Aktif',
            default => '-',
        };
    }

    private function validateBusinessRules(): void
    {
        $errors = [];

        if (
            $this->age_min_months !== null &&
            $this->age_max_months !== null &&
            $this->age_min_months >
                $this->age_max_months
        ) {
            $errors['age_max_months'] =
                'Usia maksimum harus lebih besar atau sama dengan usia minimum.';
        }

        if (
            $this->minimum_value !== null &&
            (float) $this->minimum_value >
                (float) $this->target_value
        ) {
            $errors['minimum_value'] =
                'Nilai minimum tidak boleh lebih besar dari nilai target.';
        }

        if (
            $this->maximum_value !== null &&
            (float) $this->maximum_value <
                (float) $this->target_value
        ) {
            $errors['maximum_value'] =
                'Nilai maksimum tidak boleh lebih kecil dari nilai target.';
        }

        if (
            $this->effective_from &&
            $this->effective_until &&
            $this->effective_until->lt(
                $this->effective_from
            )
        ) {
            $errors['effective_until'] =
                'Tanggal selesai harus sama atau setelah tanggal mulai.';
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages(
                $errors
            );
        }
    }
}