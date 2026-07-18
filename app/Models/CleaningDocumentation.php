<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CleaningDocumentation extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_session_id', 'phase', 'photo_path', 'caption', 'captured_at',
        'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function cleaningSession(): BelongsTo { return $this->belongsTo(CleaningSession::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
