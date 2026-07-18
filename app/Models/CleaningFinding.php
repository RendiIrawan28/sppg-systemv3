<?php

namespace App\Models;

use App\Enums\CleaningFindingSeverity;
use App\Enums\CleaningFindingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CleaningFinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_session_id', 'found_at', 'category', 'severity', 'description',
        'corrective_action', 'due_at', 'status', 'resolved_at', 'resolved_by',
        'photo_path', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'found_at' => 'datetime',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'severity' => CleaningFindingSeverity::class,
            'status' => CleaningFindingStatus::class,
        ];
    }

    public function cleaningSession(): BelongsTo { return $this->belongsTo(CleaningSession::class); }
    public function resolver(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }
}
