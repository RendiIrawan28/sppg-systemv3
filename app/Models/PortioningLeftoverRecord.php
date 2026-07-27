<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortioningLeftoverRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'portioning_session_id',
        'checked_at',
        'food_type',
        'quantity',
        'unit_name',
        'notes',
        'photo_path',
        'photo_original_name',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'quantity' => 'decimal:3',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }
}
