<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparationDocumentation extends Model
{
    protected $fillable = ['preparation_session_id', 'photo_path', 'captured_at', 'created_by'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PreparationSession::class, 'preparation_session_id');
    }
}
