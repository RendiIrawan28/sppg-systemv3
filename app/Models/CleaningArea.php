<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CleaningArea extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'sppg_unit_id', 'code', 'name', 'category', 'template_type', 'location', 'frequency',
        'auto_schedule', 'scheduled_time', 'standard_duration_minutes', 'instructions', 'default_checklist',
        'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'default_checklist' => 'array',
            'is_active' => 'boolean',
            'auto_schedule' => 'boolean',
            'standard_duration_minutes' => 'integer',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CleaningSession::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
