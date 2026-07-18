<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuCategoryTarget extends Model
{
    use HasFactory;

    protected $table = 'menu_beneficiary_category';

    protected $fillable = [
        'menu_id',
        'beneficiary_category_id',
        'portion_multiplier',
    ];

    protected function casts(): array
    {
        return [
            'menu_id' => 'integer',
            'beneficiary_category_id' => 'integer',
            'portion_multiplier' => 'decimal:4',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(
            Menu::class,
            'menu_id'
        );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            BeneficiaryCategory::class,
            'beneficiary_category_id'
        );
    }
}