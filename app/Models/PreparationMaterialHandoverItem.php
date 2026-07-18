<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PreparationMaterialHandoverItem extends Model
{
    protected $fillable = [
        'preparation_material_handover_id',
        'ingredient_id',
        'ingredient_name_snapshot',
        'unit_snapshot',
        'requested_quantity',
        'handed_over_quantity',
        'requested_quantity_kg',
        'handed_over_quantity_kg',
        'received_quantity',
        'good_quantity',
        'moderate_quantity',
        'damaged_quantity',
        'inspection_status',
        'inspection_notes',
        'inspection_photo_path',
        'prepared_quantity',
        'preparation_notes',
        'waste_type',
        'waste_quantity',
        'waste_unit_snapshot',
        'waste_notes',
        'supplier_batch_number',
        'expired_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:4',
            'handed_over_quantity' => 'decimal:4',
            'requested_quantity_kg' => 'decimal:4',
            'handed_over_quantity_kg' => 'decimal:4',
            'received_quantity' => 'decimal:4',
            'good_quantity' => 'decimal:4',
            'moderate_quantity' => 'decimal:4',
            'damaged_quantity' => 'decimal:4',
            'prepared_quantity' => 'decimal:4',
            'waste_quantity' => 'decimal:4',
            'expired_date' => 'date',
        ];
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(PreparationMaterialHandover::class, 'preparation_material_handover_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function getInspectionPhotoUrlAttribute(): ?string
    {
        if ($this->inspection_photo_path) {
            return Storage::disk('public')->url($this->inspection_photo_path);
        }

        return null;
    }

    public function displayQuantity(string $field, ?string $fallbackField = null): float
    {
        $value = $this->{$field};

        if ($value === null && $fallbackField) {
            $value = $this->{$fallbackField};
        }

        return (float) ($value ?? 0);
    }
}
