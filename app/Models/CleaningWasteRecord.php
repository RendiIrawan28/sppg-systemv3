<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CleaningWasteRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_session_id', 'waste_type', 'quantity', 'unit',
        'disposal_method', 'handed_over_to', 'photo_path', 'notes',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function cleaningSession(): BelongsTo { return $this->belongsTo(CleaningSession::class); }
}
