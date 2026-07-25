<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparationHistory extends Model
{
    protected $fillable = ['preparation_session_id', 'actor_id', 'action', 'from_state', 'to_state', 'from_status', 'to_status', 'notes', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PreparationSession::class, 'preparation_session_id');
    }
}
