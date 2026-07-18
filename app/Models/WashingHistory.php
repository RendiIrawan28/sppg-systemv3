<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WashingHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'washing_session_id', 'user_id', 'action', 'previous_state', 'new_state',
        'previous_status', 'new_status', 'notes', 'snapshot',
    ];

    protected function casts(): array { return ['snapshot' => 'array']; }
    public function washingSession(): BelongsTo { return $this->belongsTo(WashingSession::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
