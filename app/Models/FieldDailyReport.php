<?php

namespace App\Models;

use App\Enums\FieldDailyReportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class FieldDailyReport extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'field_distribution_plan_id',
        'report_number',
        'report_year',
        'sequence_number',
        'report_date',
        'generated_at',
        'planned_beneficiaries',
        'confirmed_beneficiaries',
        'actual_beneficiaries',
        'planned_portions',
        'produced_portions',
        'portioned_portions',
        'delivered_portions',
        'returned_portions',
        'planned_destinations',
        'completed_destinations',
        'failed_destinations',
        'late_destinations',
        'containers_sent',
        'containers_returned',
        'containers_damaged',
        'containers_lost',
        'open_incidents',
        'resolved_incidents',
        'operational_summary',
        'obstacles',
        'evaluation',
        'follow_up',
        'recommendations',
        'status',
        'prepared_by',
        'prepared_by_name_snapshot',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'generated_at' => 'datetime',
            'report_year' => 'integer',
            'sequence_number' => 'integer',
            'planned_beneficiaries' => 'integer',
            'confirmed_beneficiaries' => 'integer',
            'actual_beneficiaries' => 'integer',
            'planned_portions' => 'integer',
            'produced_portions' => 'integer',
            'portioned_portions' => 'integer',
            'delivered_portions' => 'integer',
            'returned_portions' => 'integer',
            'planned_destinations' => 'integer',
            'completed_destinations' => 'integer',
            'failed_destinations' => 'integer',
            'late_destinations' => 'integer',
            'containers_sent' => 'integer',
            'containers_returned' => 'integer',
            'containers_damaged' => 'integer',
            'containers_lost' => 'integer',
            'open_incidents' => 'integer',
            'resolved_incidents' => 'integer',
            'status' => FieldDailyReportStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            $report->uuid ??= (string) Str::uuid();
            $report->status ??= FieldDailyReportStatus::Draft;
            $report->assignSequence();
            $report->report_number ??= $report->buildReportNumber();
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FieldDistributionPlan::class, 'field_distribution_plan_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(FieldDailyReportDivision::class)
            ->orderByRaw("FIELD(division_code, 'preparation', 'processing', 'portioning', 'distribution', 'washing', 'cleaning')");
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(FieldDailyReportIncident::class)
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')");
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [
            FieldDailyReportStatus::Draft,
            FieldDailyReportStatus::RevisionRequired,
        ], true);
    }

    public function canBeDeleted(): bool
    {
        return $this->status === FieldDailyReportStatus::Draft;
    }

    private function assignSequence(): void
    {
        $date = $this->report_date
            ? Carbon::parse($this->report_date)
            : now();

        $this->report_year ??= (int) $date->format('Y');

        if ($this->sequence_number !== null) {
            return;
        }

        $lastSequence = self::query()
            ->withTrashed()
            ->where('sppg_unit_id', $this->sppg_unit_id)
            ->where('report_year', $this->report_year)
            ->max('sequence_number');

        $this->sequence_number = ((int) $lastSequence) + 1;
    }

    private function buildReportNumber(): string
    {
        $unitCode = SppgUnit::query()
            ->whereKey($this->sppg_unit_id)
            ->value('code') ?: 'SPPG';

        return sprintf(
            'LHA/%s/%d/%04d',
            strtoupper((string) $unitCode),
            $this->report_year,
            $this->sequence_number,
        );
    }
}
