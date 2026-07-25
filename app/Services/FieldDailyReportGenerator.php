<?php

namespace App\Services;

use App\Enums\FieldDailyReportStatus;
use App\Models\FieldDailyReport;
use App\Models\FieldDistributionPlan;
use App\Models\User;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FieldDailyReportGenerator
{
    public function generateAutomatic(int $unitId, string $date, User $actor): FieldDailyReport
    {
        $existing = FieldDailyReport::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('report_date', $date)
            ->first();

        if ($existing && ! $existing->isEditable()) {
            return $existing->load(['plan', 'divisions', 'incidents']);
        }

        $report = $this->generate($unitId, $date, $actor, $existing);

        $report->forceFill([
            'status' => FieldDailyReportStatus::Approved,
            'submitted_by' => $actor->getKey(),
            'submitted_at' => now(),
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
            'operational_summary' => $report->operational_summary
                ?: 'Laporan dibentuk otomatis setelah rencana distribusi diselesaikan.',
        ])->save();

        return $report->refresh()->load(['plan', 'divisions', 'incidents']);
    }

    public function generate(
        int $unitId,
        string $date,
        User $actor,
        ?FieldDailyReport $report = null,
    ): FieldDailyReport {
        return DB::transaction(function () use ($unitId, $date, $actor, $report): FieldDailyReport {
            $report ??= FieldDailyReport::query()
                ->where('sppg_unit_id', $unitId)
                ->whereDate('report_date', $date)
                ->first();

            if ($report && ! $report->isEditable()) {
                throw new DomainException('Laporan yang sudah diajukan atau disetujui tidak dapat dibuat ulang.');
            }

            $plans = FieldDistributionPlan::query()
                ->where('sppg_unit_id', $unitId)
                ->whereDate('distribution_date', $date)
                ->where('status', '!=', 'cancelled')
                ->latest('id')
                ->get();
            $plan = $plans->first();

            $distribution = $this->distributionSummary($unitId, $date);
            $processing = $this->processingSummary($unitId, $date);
            $portioning = $this->portioningSummary($unitId, $date);

            $report ??= new FieldDailyReport;
            $report->forceFill([
                'sppg_unit_id' => $unitId,
                'field_distribution_plan_id' => $plan?->getKey(),
                'report_date' => $date,
                'generated_at' => now(),
                'planned_beneficiaries' => (int) $plans->sum('planned_beneficiaries'),
                'confirmed_beneficiaries' => (int) $plans->sum('confirmed_beneficiaries'),
                'actual_beneficiaries' => $distribution['delivered_portions'],
                'planned_portions' => $plans->isNotEmpty()
                    ? (int) $plans->sum('planned_total_portions')
                    : $distribution['planned_portions'],
                'produced_portions' => $processing['produced_portions'],
                'portioned_portions' => $portioning['portioned_portions'],
                'delivered_portions' => $distribution['delivered_portions'],
                'returned_portions' => $distribution['returned_portions'],
                'planned_destinations' => $plans->isNotEmpty()
                    ? (int) $plans->sum('destination_count')
                    : $distribution['planned_destinations'],
                'completed_destinations' => $distribution['completed_destinations'],
                'failed_destinations' => $distribution['failed_destinations'],
                'late_destinations' => $distribution['late_destinations'],
                'containers_sent' => $distribution['containers_sent'],
                'containers_returned' => $distribution['containers_returned'],
                'containers_damaged' => $distribution['containers_damaged'],
                'containers_lost' => $distribution['containers_lost'],
                'prepared_by' => $report->prepared_by ?: $actor->getKey(),
                'prepared_by_name_snapshot' => $report->prepared_by_name_snapshot ?: $actor->name,
                'status' => $report->status ?: FieldDailyReportStatus::Draft,
            ])->save();

            $this->refreshDivisions($report, $unitId, $date);
            $this->refreshIncidents($report, $unitId, $date);

            $report->forceFill([
                'open_incidents' => $report->incidents()
                    ->whereIn('status', ['open', 'in_progress', 'pending'])
                    ->count(),
                'resolved_incidents' => $report->incidents()
                    ->whereIn('status', ['resolved', 'closed', 'completed'])
                    ->count(),
            ])->save();

            return $report->refresh()->load(['plan', 'divisions', 'incidents']);
        });
    }

    private function processingSummary(int $unitId, string $date): array
    {
        if (! Schema::hasTable('processing_batches')) {
            return ['produced_portions' => 0];
        }

        $value = DB::table('processing_batches')
            ->where('sppg_unit_id', $unitId)
            ->whereDate('production_date', $date)
            ->whereNull('deleted_at')
            ->sum('actual_output_quantity');

        return ['produced_portions' => max(0, (int) round((float) $value))];
    }

    private function portioningSummary(int $unitId, string $date): array
    {
        if (! Schema::hasTable('portioning_sessions')) {
            return ['portioned_portions' => 0];
        }

        $query = DB::table('portioning_sessions')
            ->where('sppg_unit_id', $unitId)
            ->whereDate('portioning_date', $date)
            ->whereNull('deleted_at');

        return [
            'portioned_portions' => (int) $query->sum(DB::raw(
                'COALESCE(actual_small_portions, 0) + COALESCE(actual_large_portions, 0)'
            )),
        ];
    }

    private function distributionSummary(int $unitId, string $date): array
    {
        $empty = [
            'planned_portions' => 0,
            'delivered_portions' => 0,
            'returned_portions' => 0,
            'planned_destinations' => 0,
            'completed_destinations' => 0,
            'failed_destinations' => 0,
            'late_destinations' => 0,
            'containers_sent' => 0,
            'containers_returned' => 0,
            'containers_damaged' => 0,
            'containers_lost' => 0,
        ];

        if (! Schema::hasTable('distribution_runs')) {
            return $empty;
        }

        $runs = DB::table('distribution_runs')
            ->where('sppg_unit_id', $unitId)
            ->whereDate('distribution_date', $date)
            ->whereNull('deleted_at');

        $summary = $empty;
        $summary['planned_portions'] = (int) (clone $runs)->sum(DB::raw(
            'COALESCE(planned_small_portions, 0) + COALESCE(planned_large_portions, 0)'
        ));
        $summary['delivered_portions'] = (int) (clone $runs)->sum(DB::raw(
            'COALESCE(delivered_small_portions, 0) + COALESCE(delivered_large_portions, 0)'
        ));
        $summary['returned_portions'] = (int) (clone $runs)->sum(DB::raw(
            'COALESCE(returned_small_portions, 0) + COALESCE(returned_large_portions, 0)'
        ));

        if (! Schema::hasTable('distribution_stops')) {
            return $summary;
        }

        $stops = DB::table('distribution_stops as stops')
            ->join('distribution_runs as runs', 'runs.id', '=', 'stops.distribution_run_id')
            ->where('runs.sppg_unit_id', $unitId)
            ->whereDate('runs.distribution_date', $date)
            ->whereNull('runs.deleted_at');

        $summary['planned_destinations'] = (clone $stops)->count();
        $summary['completed_destinations'] = (clone $stops)
            ->whereIn('stops.status', ['delivered', 'partial'])
            ->count();
        $summary['failed_destinations'] = (clone $stops)
            ->where('stops.status', 'failed')
            ->count();
        $summary['late_destinations'] = (clone $stops)
            ->where('stops.delay_minutes', '>', 0)
            ->count();
        $summary['containers_sent'] = (int) (clone $stops)->sum('stops.containers_sent');
        $summary['containers_returned'] = (int) (clone $stops)->sum('stops.containers_returned');
        $summary['containers_damaged'] = (int) (clone $stops)->sum('stops.containers_damaged');
        $summary['containers_lost'] = (int) (clone $stops)->sum('stops.containers_lost');

        return $summary;
    }

    private function refreshDivisions(FieldDailyReport $report, int $unitId, string $date): void
    {
        $definitions = [
            'preparation' => [
                'name' => 'Persiapan',
                'sources' => [
                    ['table' => 'preparation_sessions', 'date' => 'preparation_date'],
                ],
            ],
            'processing' => [
                'name' => 'Pengolahan',
                'sources' => [['table' => 'processing_batches', 'date' => 'production_date']],
            ],
            'portioning' => [
                'name' => 'Pemorsian',
                'sources' => [['table' => 'portioning_sessions', 'date' => 'portioning_date']],
            ],
            'distribution' => [
                'name' => 'Distribusi',
                'sources' => [['table' => 'distribution_runs', 'date' => 'distribution_date']],
            ],
            'washing' => [
                'name' => 'Pencucian',
                'sources' => [
                    ['table' => 'washing_sessions', 'date' => 'washing_date'],
                    ['table' => 'waste_handover_reports', 'date' => 'report_date', 'where' => ['division_type' => 'washing']],
                ],
            ],
            'cleaning' => [
                'name' => 'Kebersihan',
                'sources' => [
                    ['table' => 'cleaning_sessions', 'date' => 'scheduled_date'],
                    ['table' => 'waste_handover_reports', 'date' => 'report_date', 'where' => ['division_type' => 'cleaning']],
                ],
            ],
        ];

        foreach ($definitions as $code => $definition) {
            $rows = collect();

            foreach ($definition['sources'] as $source) {
                $rows = $rows->merge($this->statusRows($source, $unitId, $date));
            }

            $total = $rows->count();
            $draft = $rows->where('status', 'draft')->count();
            $submitted = $rows->where('status', 'submitted')->count();
            $revision = $rows->where('status', 'revision_required')->count();
            $verified = $rows->whereIn('status', ['verified', 'approved'])->count();

            $completion = match (true) {
                $total === 0 => 'not_started',
                $revision > 0 => 'revision_required',
                $verified === $total => 'verified',
                $submitted > 0 => 'submitted',
                default => 'in_progress',
            };

            $report->divisions()->updateOrCreate(
                ['division_code' => $code],
                [
                    'division_name' => $definition['name'],
                    'total_records' => $total,
                    'draft_records' => $draft,
                    'submitted_records' => $submitted,
                    'revision_records' => $revision,
                    'verified_records' => $verified,
                    'completion_status' => $completion,
                    'last_activity_at' => $rows->max('updated_at'),
                ]
            );
        }
    }

    private function statusRows(array $source, int $unitId, string $date): Collection
    {
        $table = $source['table'];

        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'sppg_unit_id')
            || ! Schema::hasColumn($table, $source['date'])
            || ! Schema::hasColumn($table, 'status')) {
            return collect();
        }

        $query = DB::table($table)
            ->where('sppg_unit_id', $unitId)
            ->whereDate($source['date'], $date);

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        foreach ($source['where'] ?? [] as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $query->where($column, $value);
            }
        }

        return $query->get([
            'status',
            Schema::hasColumn($table, 'updated_at') ? 'updated_at' : DB::raw('NULL as updated_at'),
        ]);
    }

    private function refreshIncidents(FieldDailyReport $report, int $unitId, string $date): void
    {
        $report->incidents()->delete();
        $snapshots = collect();

        if (Schema::hasTable('field_incidents')) {
            $snapshots = $snapshots->merge(
                DB::table('field_incidents')
                    ->where('sppg_unit_id', $unitId)
                    ->where(function (Builder $query) use ($date): void {
                        $query->whereDate('incident_date', $date)
                            ->orWhere(function (Builder $query) use ($date): void {
                                $query->whereDate('incident_date', '<=', $date)
                                    ->whereIn('status', ['open', 'in_progress']);
                            });
                    })
                    ->whereNull('deleted_at')
                    ->get()
                    ->map(fn ($row): array => [
                        'source_type' => 'field_incident',
                        'source_id' => $row->id,
                        'division_code' => $row->division_code,
                        'category' => $row->category,
                        'severity' => $row->severity,
                        'status' => $row->status,
                        'title' => $row->title,
                        'description' => $row->description,
                        'action_or_resolution' => $row->resolution ?: $row->immediate_action,
                    ])
            );
        }

        $snapshots = $snapshots
            ->merge($this->joinedIncidentRows(
                'portioning_deviations', 'portioning_sessions', 'portioning_session_id',
                'portioning_date', 'portioning', 'corrective_action', $unitId, $date
            ))
            ->merge($this->joinedIncidentRows(
                'distribution_incidents', 'distribution_runs', 'distribution_run_id',
                'distribution_date', 'distribution', 'immediate_action', $unitId, $date
            ))
            ->merge($this->joinedIncidentRows(
                'washing_deviations', 'washing_sessions', 'washing_session_id',
                'washing_date', 'washing', 'immediate_action', $unitId, $date
            ))
            ->merge($this->joinedIncidentRows(
                'cleaning_findings', 'cleaning_sessions', 'cleaning_session_id',
                'scheduled_date', 'cleaning', 'corrective_action', $unitId, $date
            ));

        foreach ($snapshots as $snapshot) {
            $report->incidents()->create($snapshot);
        }
    }

    private function joinedIncidentRows(
        string $incidentTable,
        string $parentTable,
        string $foreignKey,
        string $dateColumn,
        string $divisionCode,
        string $actionColumn,
        int $unitId,
        string $date,
    ): Collection {
        if (! Schema::hasTable($incidentTable) || ! Schema::hasTable($parentTable)) {
            return collect();
        }

        return DB::table("{$incidentTable} as incidents")
            ->join("{$parentTable} as parents", 'parents.id', '=', "incidents.{$foreignKey}")
            ->where('parents.sppg_unit_id', $unitId)
            ->whereDate("parents.{$dateColumn}", $date)
            ->when(
                Schema::hasColumn($parentTable, 'deleted_at'),
                fn (Builder $query) => $query->whereNull('parents.deleted_at')
            )
            ->get([
                'incidents.id',
                'incidents.category',
                'incidents.severity',
                'incidents.status',
                'incidents.description',
                "incidents.{$actionColumn} as action_text",
            ])
            ->map(fn ($row): array => [
                'source_type' => $incidentTable,
                'source_id' => $row->id,
                'division_code' => $divisionCode,
                'category' => $row->category,
                'severity' => $row->severity,
                'status' => $row->status,
                'title' => ucwords(str_replace('_', ' ', (string) $row->category)),
                'description' => $row->description,
                'action_or_resolution' => $row->action_text,
            ]);
    }
}
