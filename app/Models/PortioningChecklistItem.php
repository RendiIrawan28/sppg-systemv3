<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortioningChecklistItem extends Model
{
    protected $fillable = ['portioning_session_id', 'category', 'item_name', 'is_mandatory', 'result', 'notes', 'checked_by', 'checked_at', 'sort_order'];

    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean', 'checked_at' => 'datetime', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->isDirty('result') && $item->result !== 'pending') {
                $item->checked_at = now();
                $item->checked_by ??= auth()->id();
            }
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }
}
