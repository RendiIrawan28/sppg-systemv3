<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortioningRouteRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'portioning_session_id',
        'route_name',
        'small_portions',
        'large_portions',
        'photo_path',
        'photo_original_name',
        'notes',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'small_portions' => 'integer',
            'large_portions' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }
}
