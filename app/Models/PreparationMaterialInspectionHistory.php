<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparationMaterialInspectionHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'preparation_material_inspection_id',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'notes',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(PreparationMaterialInspection::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
