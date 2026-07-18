<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WashingChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'washing_session_id', 'category', 'item_name', 'is_mandatory', 'is_passed',
        'checked_at', 'checked_by', 'notes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'is_passed' => 'boolean',
            'checked_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->is_passed && ! $item->checked_at) {
                $item->checked_at = now();
            }
            if ($item->is_passed && ! $item->checked_by) {
                $item->checked_by = auth()->id();
            }
        });
    }

    public function washingSession(): BelongsTo { return $this->belongsTo(WashingSession::class); }
    public function checker(): BelongsTo { return $this->belongsTo(User::class, 'checked_by'); }
}
