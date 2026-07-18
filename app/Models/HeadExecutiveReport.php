<?php

namespace App\Models;

use App\Enums\HeadExecutivePeriodType;
use App\Enums\HeadExecutiveReportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class HeadExecutiveReport extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'sppg_unit_id', 'report_number', 'period_type', 'date_from', 'date_to',
        'generated_at', 'planned_beneficiaries', 'confirmed_beneficiaries',
        'actual_beneficiaries', 'planned_portions', 'delivered_portions',
        'returned_portions', 'completed_destinations', 'failed_destinations',
        'late_destinations', 'containers_sent', 'containers_returned',
        'containers_damaged', 'containers_lost', 'pending_approvals',
        'approved_documents', 'open_incidents', 'critical_incidents',
        'division_summary', 'nutrition_summary', 'approval_summary',
        'incident_summary', 'executive_summary', 'decisions', 'follow_up',
        'status', 'generated_by', 'finalized_by', 'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => HeadExecutivePeriodType::class,
            'date_from' => 'date',
            'date_to' => 'date',
            'generated_at' => 'datetime',
            'planned_beneficiaries' => 'integer',
            'confirmed_beneficiaries' => 'integer',
            'actual_beneficiaries' => 'integer',
            'planned_portions' => 'integer',
            'delivered_portions' => 'integer',
            'returned_portions' => 'integer',
            'completed_destinations' => 'integer',
            'failed_destinations' => 'integer',
            'late_destinations' => 'integer',
            'containers_sent' => 'integer',
            'containers_returned' => 'integer',
            'containers_damaged' => 'integer',
            'containers_lost' => 'integer',
            'pending_approvals' => 'integer',
            'approved_documents' => 'integer',
            'open_incidents' => 'integer',
            'critical_incidents' => 'integer',
            'division_summary' => 'array',
            'nutrition_summary' => 'array',
            'approval_summary' => 'array',
            'incident_summary' => 'array',
            'status' => HeadExecutiveReportStatus::class,
            'finalized_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            $report->uuid ??= (string) Str::uuid();
            $report->status ??= HeadExecutiveReportStatus::Draft;
            $report->report_number ??= self::nextNumber(
                (int) $report->sppg_unit_id,
                $report->date_from?->format('Y') ?? now()->format('Y')
            );
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isEditable(): bool
    {
        return $this->status === HeadExecutiveReportStatus::Draft;
    }

    private static function nextNumber(int $unitId, string $year): string
    {
        $unitCode = SppgUnit::query()->whereKey($unitId)->value('code') ?: 'SPPG';
        $sequence = self::query()
            ->withTrashed()
            ->where('sppg_unit_id', $unitId)
            ->whereYear('date_from', $year)
            ->count() + 1;

        return sprintf('LEX/%s/%s/%04d', strtoupper((string) $unitCode), $year, $sequence);
    }
}
