<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeneficiaryPeriodMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'beneficiary_period_id',
        'beneficiary_period_destination_id',
        'source_beneficiary_id',
        'beneficiary_category_id',
        'member_code',
        'identity_number',
        'name',
        'birth_date',
        'gender',
        'parent_name',
        'recipient_position',
        'education_level',
        'class_group',
        'beneficiary_category_code_snapshot',
        'beneficiary_category_name_snapshot',
        'portion_category',
        'menu_audience',
        'address',
        'allergy_notes',
        'special_needs',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'beneficiary_period_id' => 'integer',
            'beneficiary_period_destination_id' => 'integer',
            'source_beneficiary_id' => 'integer',
            'beneficiary_category_id' => 'integer',
            'birth_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $record): void {
            if ($record->period) {
                app(\App\Services\BeneficiaryPeriodSnapshotService::class)->recalculate($record->period);
            }
        });
        static::deleted(function (self $record): void {
            if ($record->period) {
                app(\App\Services\BeneficiaryPeriodSnapshotService::class)->recalculate($record->period);
            }
        });
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriod::class, 'beneficiary_period_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriodDestination::class, 'beneficiary_period_destination_id');
    }

    public function sourceBeneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'source_beneficiary_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryCategory::class, 'beneficiary_category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
