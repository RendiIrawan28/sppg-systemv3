<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WashingWasteRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'washing_session_id', 'waste_type', 'quantity', 'unit', 'disposal_method',
        'handed_over_to', 'photo_path', 'notes',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function washingSession(): BelongsTo
    {
        return $this->belongsTo(WashingSession::class);
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? Storage::disk('public')->url($this->photo_path)
            : null;
    }
}
