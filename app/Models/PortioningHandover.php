<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortioningHandover extends Model
{
    use HasFactory;

    protected $fillable = [
        'portioning_session_id',
        'handed_over_at',
        'small_portions',
        'large_portions',
        'received_by_user_id',
        'received_by_name',
        'photo_path',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'handed_over_at' => 'datetime',
            'small_portions' => 'integer',
            'large_portions' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
