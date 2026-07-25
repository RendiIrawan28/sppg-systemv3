<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeneficiaryPeriodCategoryTotal extends Model
{
    protected $fillable = [
        'beneficiary_period_id',
        'beneficiary_period_destination_id',
        'beneficiary_category_id',
        'beneficiary_category_code_snapshot',
        'beneficiary_category_name_snapshot',
        'portion_category',
        'menu_audience',
        'total_beneficiaries',
    ];

    protected function casts(): array
    {
        return [
            'beneficiary_period_id' => 'integer',
            'beneficiary_period_destination_id' => 'integer',
            'beneficiary_category_id' => 'integer',
            'total_beneficiaries' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriod::class, 'beneficiary_period_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriodDestination::class, 'beneficiary_period_destination_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryCategory::class, 'beneficiary_category_id');
    }
}
