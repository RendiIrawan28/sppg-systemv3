<?php

namespace App\Models;

use App\Enums\CleaningSessionState;
use App\Enums\OperationalReportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CleaningSession extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'sppg_unit_id', 'cleaning_area_id', 'session_number', 'session_year',
        'sequence_number', 'scheduled_date', 'shift', 'scheduled_start_at',
        'started_at', 'completed_at', 'ready_at', 'duration_minutes', 'state',
        'petugas_id', 'petugas_name_snapshot', 'supervisor_id',
        'supervisor_name_snapshot', 'before_condition', 'after_condition', 'waste_presence',
        'waste_handover_report_id', 'notes',
        'status', 'created_by', 'updated_by', 'submitted_by', 'submitted_at',
        'division_approved_by', 'division_approved_at', 'verified_by', 'verified_at',
        'review_notes', 'source_system', 'legacy_id',
        'legacy_sheet_name', 'legacy_created_at', 'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'scheduled_start_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'ready_at' => 'datetime',
            'duration_minutes' => 'integer',
            'session_year' => 'integer',
            'sequence_number' => 'integer',
            'state' => CleaningSessionState::class,
            'status' => OperationalReportStatus::class,
            'submitted_at' => 'datetime',
            'division_approved_at' => 'datetime',
            'verified_at' => 'datetime',
            'legacy_created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->uuid ??= (string) Str::uuid();
            $session->state ??= CleaningSessionState::Planned;
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
    public function cleaningArea(): BelongsTo { return $this->belongsTo(CleaningArea::class); }
    public function petugas(): BelongsTo { return $this->belongsTo(User::class, 'petugas_id'); }
    public function supervisor(): BelongsTo { return $this->belongsTo(User::class, 'supervisor_id'); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function divisionApprover(): BelongsTo { return $this->belongsTo(User::class, 'division_approved_by'); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(CleaningChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function chemicalUsages(): HasMany
    {
        return $this->hasMany(CleaningChemicalUsage::class)->orderBy('used_at')->orderBy('id');
    }

    public function documentations(): HasMany
    {
        return $this->hasMany(CleaningDocumentation::class)->orderBy('sort_order')->orderBy('id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(CleaningFinding::class)->orderBy('found_at')->orderBy('id');
    }

    public function wasteRecords(): HasMany
    {
        return $this->hasMany(CleaningWasteRecord::class)->orderBy('id');
    }

    public function wasteHandoverReport(): BelongsTo
    {
        return $this->belongsTo(WasteHandoverReport::class, 'waste_handover_report_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(CleaningHistory::class)->latest();
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('scheduled_date', $date);
    }

    public function getCompletionPercentageAttribute(): int
    {
        $items = $this->relationLoaded('checklistItems')
            ? $this->checklistItems
            : $this->checklistItems()->get();

        $required = $items->where('is_mandatory', true);
        if ($required->isEmpty()) {
            return 0;
        }

        $completed = $required->whereIn('result', ['pass', 'fail'])->count();

        return (int) round(($completed / $required->count()) * 100);
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
        return $this->isReportEditable() && $this->state === CleaningSessionState::Ready;
    }

    public function canBeDeleted(): bool
    {
        return $this->status === OperationalReportStatus::Draft
            && $this->state === CleaningSessionState::Planned;
    }

    private function assignSequence(): void
    {
        $date = $this->scheduled_date ? Carbon::parse($this->scheduled_date) : now();
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

        return sprintf('CLN/%s/%d/%04d', strtoupper((string) $unitCode), $this->session_year, $this->sequence_number);
    }
}
