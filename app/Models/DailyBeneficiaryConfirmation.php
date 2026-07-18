<?php

namespace App\Models;

use App\Support\DailyBeneficiaryConfirmationRules;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyBeneficiaryConfirmation extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id',
        'beneficiary_period_id',
        'service_date',
        'destination_type',
        'destination_id',
        'destination_name_snapshot',
        'destination_code_snapshot',
        'address_snapshot',
        'contact_name_snapshot',
        'contact_phone_snapshot',
        'status',
        'confirmed_at',
        'confirmed_by_name',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriod::class, 'beneficiary_period_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DailyBeneficiaryConfirmationItem::class);
    }

    public function scopeForUnit(Builder $query, int $unitId): Builder
    {
        return $query->where('sppg_unit_id', $unitId);
    }

    public function getMasterTotalAttribute(): int
    {
        return (int) $this->items->sum('master_count');
    }

    public function getActualTotalAttribute(): int
    {
        return (int) $this->items->sum('actual_count');
    }

    public function revisionIsAllowed(CarbonInterface|string|null $referenceDate = null): bool
    {
        return DailyBeneficiaryConfirmationRules::revisionIsAllowed(
            $this->service_date,
            $referenceDate,
        );
    }

    public function isEditable(CarbonInterface|string|null $referenceDate = null): bool
    {
        return $this->status !== 'cancelled'
            && $this->revisionIsAllowed($referenceDate);
    }
}
