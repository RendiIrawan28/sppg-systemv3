<?php

namespace App\Models;

use App\Services\BeneficiaryPeriodSnapshotService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BeneficiaryPeriodDestination extends Model
{
    use HasFactory;

    protected $fillable = [
        'beneficiary_period_id',
        'destination_key',
        'destination_type',
        'destination_id',
        'destination_code_snapshot',
        'destination_name_snapshot',
        'address_snapshot',
        'contact_name_snapshot',
        'contact_phone_snapshot',
        'latitude_snapshot',
        'longitude_snapshot',
        'preferred_delivery_time',
        'sort_order',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'destination_id' => 'integer',
            'latitude_snapshot' => 'decimal:7',
            'longitude_snapshot' => 'decimal:7',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $record): void {
            if ($record->period) {
                app(BeneficiaryPeriodSnapshotService::class)->recalculate($record->period);
            }
        });
        static::deleted(function (self $record): void {
            if ($record->period) {
                app(BeneficiaryPeriodSnapshotService::class)->recalculate($record->period);
            }
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriod::class, 'beneficiary_period_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BeneficiaryPeriodMember::class, 'beneficiary_period_destination_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('is_active', true);
    }

    public function categoryTotals(): HasMany
    {
        return $this->hasMany(BeneficiaryPeriodCategoryTotal::class, 'beneficiary_period_destination_id');
    }
}
