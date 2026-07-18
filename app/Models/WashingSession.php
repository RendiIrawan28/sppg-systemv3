<?php

namespace App\Models;

use App\Enums\OperationalReportStatus;
use App\Enums\WashingSessionState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class WashingSession extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'sppg_unit_id', 'distribution_run_id', 'session_number', 'session_year',
        'sequence_number', 'washing_date', 'menu_name_snapshot', 'expected_containers',
        'received_containers', 'washed_containers', 'clean_containers', 'damaged_containers',
        'rejected_containers', 'missing_containers', 'received_at', 'started_at',
        'completed_at', 'ready_at', 'duration_minutes', 'state', 'washing_area',
        'equipment_name', 'petugas_id', 'petugas_name_snapshot', 'notes', 'status',
        'created_by', 'updated_by', 'submitted_by', 'submitted_at', 'verified_by',
        'verified_at', 'review_notes', 'source_system', 'legacy_id', 'legacy_sheet_name',
        'legacy_created_at', 'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'washing_date' => 'date',
            'expected_containers' => 'integer',
            'received_containers' => 'integer',
            'washed_containers' => 'integer',
            'clean_containers' => 'integer',
            'damaged_containers' => 'integer',
            'rejected_containers' => 'integer',
            'missing_containers' => 'integer',
            'received_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'ready_at' => 'datetime',
            'duration_minutes' => 'integer',
            'session_year' => 'integer',
            'sequence_number' => 'integer',
            'state' => WashingSessionState::class,
            'status' => OperationalReportStatus::class,
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'legacy_created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->uuid ??= (string) Str::uuid();
            $session->state ??= WashingSessionState::Planned;
            $session->status ??= OperationalReportStatus::Draft;
            $session->source_system ??= 'laravel_v2';
            $session->assignSequence();
            $session->session_number ??= $session->buildSessionNumber();
        });

        static::saving(function (self $session): void {
            if ($session->started_at && $session->completed_at) {
                $session->duration_minutes = max(0, $session->started_at->diffInMinutes($session->completed_at));
            }
        });
    }

    public function sppgUnit(): BelongsTo { return $this->belongsTo(SppgUnit::class); }
    public function distributionRun(): BelongsTo { return $this->belongsTo(DistributionRun::class); }
    public function petugas(): BelongsTo { return $this->belongsTo(User::class, 'petugas_id'); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(WashingChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(WashingMeasurement::class)->orderBy('measured_at')->orderBy('id');
    }

    public function chemicalUsages(): HasMany
    {
        return $this->hasMany(WashingChemicalUsage::class)->orderBy('used_at')->orderBy('id');
    }

    public function wasteRecords(): HasMany
    {
        return $this->hasMany(WashingWasteRecord::class)->orderBy('id');
    }

    public function documentations(): HasMany
    {
        return $this->hasMany(WashingDocumentation::class)->orderBy('sort_order')->orderBy('id');
    }

    public function deviations(): HasMany
    {
        return $this->hasMany(WashingDeviation::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(WashingHistory::class)->latest();
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('washing_date', $date);
    }

    public function getContainerDifferenceAttribute(): int
    {
        return (int) $this->expected_containers - (int) $this->received_containers - (int) $this->missing_containers;
    }

    public function getProcessingDifferenceAttribute(): int
    {
        return (int) $this->received_containers - (int) $this->washed_containers - (int) $this->damaged_containers;
    }

    public function getQualityDifferenceAttribute(): int
    {
        return (int) $this->washed_containers - (int) $this->clean_containers - (int) $this->rejected_containers;
    }

    public function isReportEditable(): bool
    {
        return in_array($this->status, [
            OperationalReportStatus::Draft,
            OperationalReportStatus::RevisionRequired,
        ], true);
    }

    public function canBeSubmitted(): bool
    {
        return $this->isReportEditable() && $this->state === WashingSessionState::Ready;
    }

    public function canBeDeleted(): bool
    {
        return $this->status === OperationalReportStatus::Draft
            && $this->state === WashingSessionState::Planned;
    }

    private function assignSequence(): void
    {
        $date = $this->washing_date ? Carbon::parse($this->washing_date) : now();
        $this->session_year ??= (int) $date->format('Y');

        if ($this->sequence_number !== null) {
            return;
        }

        $lastSequence = self::query()
            ->withTrashed()
            ->where('sppg_unit_id', $this->sppg_unit_id)
            ->where('session_year', $this->session_year)
            ->max('sequence_number');

        $this->sequence_number = ((int) $lastSequence) + 1;
    }

    private function buildSessionNumber(): string
    {
        $unitCode = SppgUnit::query()->whereKey($this->sppg_unit_id)->value('code') ?: 'SPPG';

        return sprintf('WSH/%s/%d/%04d', strtoupper((string) $unitCode), $this->session_year, $this->sequence_number);
    }
}
