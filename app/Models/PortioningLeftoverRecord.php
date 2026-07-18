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
        'route_name',
        'checked_at',
        'food_type',
        'weight_kg',
        'reason',
        'notes',
        'photo_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'weight_kg' => 'decimal:3',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }
}
