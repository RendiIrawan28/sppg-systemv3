<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBeneficiaryConfirmationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'daily_beneficiary_confirmation_id',
        'beneficiary_category_id',
        'beneficiary_category_code_snapshot',
        'beneficiary_category_name_snapshot',
        'portion_category',
        'menu_audience',
        'master_count',
        'actual_count',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'master_count' => 'integer',
            'actual_count' => 'integer',
        ];
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(DailyBeneficiaryConfirmation::class, 'daily_beneficiary_confirmation_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryCategory::class, 'beneficiary_category_id');
    }
}
