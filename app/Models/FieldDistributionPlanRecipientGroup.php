<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldDistributionPlanRecipientGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'field_distribution_plan_destination_id',
        'beneficiary_category_id',
        'beneficiary_category_code_snapshot',
        'beneficiary_category_name_snapshot',
        'menu_audience',
        'portion_size',
        'registered_beneficiaries',
        'confirmed_beneficiaries',
        'small_portions',
        'large_portions',
        'total_portions',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'beneficiary_category_id' => 'integer',
            'registered_beneficiaries' => 'integer',
            'confirmed_beneficiaries' => 'integer',
            'small_portions' => 'integer',
            'large_portions' => 'integer',
            'total_portions' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $group): void {
            $confirmed = (int) $group->confirmed_beneficiaries;

            if ($group->portion_size === 'large') {
                $group->large_portions = $confirmed;
                $group->small_portions = 0;
            } else {
                $group->small_portions = $confirmed;
                $group->large_portions = 0;
            }

            $group->total_portions = $group->small_portions + $group->large_portions;
        });

        static::saved(fn (self $group) => $group->destination?->recalculatePortionsFromGroups());
        static::deleted(fn (self $group) => $group->destination?->recalculatePortionsFromGroups());
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(
            FieldDistributionPlanDestination::class,
            'field_distribution_plan_destination_id',
        );
    }

    public function beneficiaryCategory(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryCategory::class);
    }
}
