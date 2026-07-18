<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeneficiaryPeriodItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'beneficiary_period_id',
        'destination_type',
        'destination_id',
        'destination_name_snapshot',
        'destination_code_snapshot',
        'address_snapshot',
        'contact_name_snapshot',
        'contact_phone_snapshot',
        'beneficiary_category_id',
        'beneficiary_category_code_snapshot',
        'beneficiary_category_name_snapshot',
        'portion_category',
        'menu_audience',
        'master_count',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'master_count' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriod::class, 'beneficiary_period_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryCategory::class, 'beneficiary_category_id');
    }
}
