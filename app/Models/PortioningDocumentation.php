<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortioningDocumentation extends Model
{
    use HasFactory;

    protected $fillable = [
        'portioning_session_id',
        'phase',
        'photo_path',
        'caption',
        'captured_at',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }
}
