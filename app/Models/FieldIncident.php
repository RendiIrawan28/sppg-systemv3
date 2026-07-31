<?php

namespace App\Models;

use App\Enums\FieldIncidentSeverity;
use App\Enums\FieldIncidentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class FieldIncident extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'incident_date',
        'occurred_at',
        'division_code',
        'category',
        'severity',
        'title',
        'description',
        'location',
        'source_type',
        'source_id',
        'responsible_user_id',
        'responsible_name_snapshot',
        'due_at',
        'status',
        'immediate_action',
        'root_cause',
        'resolution',
        'resolved_at',
        'resolved_by',
        'evidence_paths',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'occurred_at' => 'datetime',
            'severity' => FieldIncidentSeverity::class,
            'status' => FieldIncidentStatus::class,
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'evidence_paths' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $incident): void {
            $incident->uuid ??= (string) Str::uuid();
            $incident->incident_date ??= now()->toDateString();
            $incident->occurred_at ??= now();
            $incident->severity ??= FieldIncidentSeverity::Medium;
            $incident->status ??= FieldIncidentStatus::Open;
        });
    }

    public function getEvidencePhotoAttribute(): ?string
    {
        $paths = $this->evidence_paths ?? [];

        return is_array($paths) ? ($paths[0] ?? null) : null;
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            FieldIncidentStatus::Open->value,
            FieldIncidentStatus::InProgress->value,
        ]);
    }
}
