<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WasteHandoverItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'waste_handover_report_id',
        'waste_type',
        'weight_kg',
        'notes',
        'photo_path',
        'legacy_photo_url',
        'sort_order',
        'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(
            WasteHandoverReport::class,
            'waste_handover_report_id'
        );
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if ($this->photo_path) {
            return Storage::disk('public')->url($this->photo_path);
        }

        return $this->legacy_photo_url ?: null;
    }
}
