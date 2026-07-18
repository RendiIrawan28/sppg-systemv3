<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CleaningChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_session_id', 'category', 'item_name', 'is_mandatory', 'result',
        'checked_at', 'checked_by', 'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'checked_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function cleaningSession(): BelongsTo { return $this->belongsTo(CleaningSession::class); }
    public function checker(): BelongsTo { return $this->belongsTo(User::class, 'checked_by'); }
}
