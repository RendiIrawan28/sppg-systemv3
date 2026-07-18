<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WashingDocumentation extends Model
{
    use HasFactory;

    protected $fillable = [
        'washing_session_id', 'phase', 'photo_path', 'caption', 'captured_at',
        'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $documentation): void {
            $documentation->created_by ??= auth()->id();
        });
    }

    public function washingSession(): BelongsTo { return $this->belongsTo(WashingSession::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
