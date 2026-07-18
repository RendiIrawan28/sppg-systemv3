<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\NutritionDailyReport;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class NutritionDailyReportBuilder
{
    public function build(NutritionDailyReport $report): void
    {
        if (! $report->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Laporan yang sudah diajukan tidak dapat dibuat ulang.',
            ]);
        }

        $date = $report->report_date?->toDateString();

        if (! $date) {
            throw ValidationException::withMessages([
                'report_date' => 'Tanggal laporan wajib diisi.',
            ]);
        }

        $menuId = $report->menu_id ?: $this->findMenuId(
            (int) $report->sppg_unit_id,
            $date
        );

        $menu = $menuId
            ? Menu::query()
                ->where('sppg_unit_id', $report->sppg_unit_id)
                ->find($menuId)
            : null;

        $planned = $this->plannedSummary(
            unitId: (int) $report->sppg_unit_id,
            date: $date,
            fallbackPortions: (int) ($menu?->planned_portions ?? 0),
        );

        $actual = $this->actualDistributionSummary(
            unitId: (int) $report->sppg_unit_id,
            date: $date,
        );

        $evaluation = DB::table('menu_acceptance_evaluations')
            ->where('sppg_unit_id', $report->sppg_unit_id)
            ->whereDate('evaluation_date', $date)
            ->selectRaw('AVG(acceptance_percent) AS acceptance')
            ->selectRaw('AVG(waste_percent) AS waste')
            ->first();

        DB::transaction(function () use (
            $report,
            $menu,
            $planned,
            $actual,
            $evaluation,
            $date
        ): void {
            $locked = NutritionDailyReport::query()
                ->lockForUpdate()
                ->findOrFail($report->getKey());

            $locked->update([
                'menu_id' => $menu?->getKey(),
                'planned_beneficiaries' => $planned['beneficiaries'],
                'actual_beneficiaries' => $actual['served'],
                'planned_portions' => $planned['portions'],
                'served_portions' => $actual['served'],
                'returned_portions' => $actual['returned'],
                'average_acceptance_percent' =>
                    $evaluation?->acceptance !== null
                        ? round((float) $evaluation->acceptance, 2)
                        : null,
                'average_waste_percent' =>
                    $evaluation?->waste !== null
                        ? round((float) $evaluation->waste, 2)
                        : null,
                'special_menu_count' => $this->specialMenuCount(
                    (int) $locked->sppg_unit_id,
                    $date
                ),
                'allergen_conflicts_count' => $this->allergenConflictCount(
                    (int) $locked->sppg_unit_id,
                    $date
                ),
                'open_findings_count' => $this->openFindingsCount(
                    (int) $locked->sppg_unit_id,
                    $date
                ),
                'summary' => sprintf(
                    'Menu %s. Rencana %s porsi untuk %s penerima; tersaji %s porsi dan kembali %s porsi.',
                    $menu?->name ?? 'belum ditentukan',
                    number_format($planned['portions'], 0, ',', '.'),
                    number_format($planned['beneficiaries'], 0, ',', '.'),
                    number_format($actual['served'], 0, ',', '.'),
                    number_format($actual['returned'], 0, ',', '.')
                ),
                'generated_by' => auth()->id(),
                'generated_at' => now(),
            ]);

            $locked->components()->delete();

            if ($menu) {
                $this->storeComponents(
                    report: $locked,
                    menu: $menu,
                    plannedPortions: $planned['portions'],
                    actualPortions: $actual['served'],
                );
            }
        });

        $report->refresh();
    }

    /**
     * @return array<int, string>
     */
    public function readinessIssues(NutritionDailyReport $report): array
    {
        $issues = [];

        if (! $report->generated_at) {
            $issues[] = 'Rekap laporan belum dibuat.';
        }

        if (! $report->menu_id) {
            $issues[] = 'Menu pelayanan belum ditemukan.';
        }

        if ($report->components()->count() === 0) {
            $issues[] = 'Ringkasan komponen gizi belum tersedia.';
        }

        if (blank($report->evaluation_notes)) {
            $issues[] = 'Catatan evaluasi Ahli Gizi belum diisi.';
        }

        if (
            (int) $report->open_findings_count > 0 &&
            blank($report->recommendations)
        ) {
            $issues[] = 'Masih ada temuan terbuka dan rekomendasi belum diisi.';
        }

        return $issues;
    }

    private function findMenuId(int $unitId, string $date): ?int
    {
        if (Schema::hasTable('field_distribution_plans')) {
            $menuId = DB::table('field_distribution_plans')
                ->where('sppg_unit_id', $unitId)
                ->whereDate('distribution_date', $date)
                ->whereNotNull('menu_id')
                ->orderByDesc('id')
                ->value('menu_id');

            if ($menuId) {
                return (int) $menuId;
            }
        }

        return Menu::query()
            ->where('sppg_unit_id', $unitId)
            ->whereDate('service_date', $date)
            ->orderByDesc('id')
            ->value('id');
    }

    /**
     * @return array{beneficiaries:int, portions:int}
     */
    private function plannedSummary(
        int $unitId,
        string $date,
        int $fallbackPortions,
    ): array {
        if (! Schema::hasTable('field_distribution_plans')) {
            return [
                'beneficiaries' => $fallbackPortions,
                'portions' => $fallbackPortions,
            ];
        }

        $row = DB::table('field_distribution_plans')
            ->where('sppg_unit_id', $unitId)
            ->whereDate('distribution_date', $date)
            ->selectRaw('COALESCE(SUM(confirmed_beneficiaries), 0) AS beneficiaries')
            ->selectRaw('COALESCE(SUM(planned_total_portions), 0) AS portions')
            ->first();

        $beneficiaries = (int) ($row?->beneficiaries ?? 0);
        $portions = (int) ($row?->portions ?? 0);

        return [
            'beneficiaries' => $beneficiaries > 0
                ? $beneficiaries
                : $fallbackPortions,
            'portions' => $portions > 0
                ? $portions
                : $fallbackPortions,
        ];
    }

    /**
     * @return array{served:int, returned:int}
     */
    private function actualDistributionSummary(int $unitId, string $date): array
    {
        if (
            ! Schema::hasTable('distribution_runs') ||
            ! Schema::hasTable('distribution_stops')
        ) {
            return ['served' => 0, 'returned' => 0];
        }

        $runIds = DB::table('distribution_runs')
            ->where('sppg_unit_id', $unitId)
            ->whereDate('distribution_date', $date)
            ->pluck('id');

        if ($runIds->isEmpty()) {
            return ['served' => 0, 'returned' => 0];
        }

        $columns = Schema::getColumnListing('distribution_stops');

        $deliveredColumns = array_values(array_intersect($columns, [
            'delivered_small_portions',
            'delivered_large_portions',
            'actual_small_portions',
            'actual_large_portions',
            'delivered_portions',
        ]));

        $returnedColumns = array_values(array_intersect($columns, [
            'returned_small_portions',
            'returned_large_portions',
            'returned_portions',
        ]));

        return [
            'served' => $this->sumColumns(
                DB::table('distribution_stops')
                    ->whereIn('distribution_run_id', $runIds),
                $deliveredColumns
            ),
            'returned' => $this->sumColumns(
                DB::table('distribution_stops')
                    ->whereIn('distribution_run_id', $runIds),
                $returnedColumns
            ),
        ];
    }

    /**
     * @param array<int, string> $columns
     */
    private function sumColumns(Builder $query, array $columns): int
    {
        if ($columns === []) {
            return 0;
        }

        $expression = implode(
            ' + ',
            array_map(
                fn (string $column): string => "COALESCE(`{$column}`, 0)",
                $columns
            )
        );

        return (int) $query->selectRaw(
            "COALESCE(SUM({$expression}), 0) AS total"
        )->value('total');
    }

    private function specialMenuCount(int $unitId, string $date): int
    {
        if (
            ! Schema::hasTable('menus') ||
            ! Schema::hasColumn('menus', 'menu_type')
        ) {
            return 0;
        }

        return DB::table('menus')
            ->where('sppg_unit_id', $unitId)
            ->whereDate('service_date', $date)
            ->where('menu_type', 'special')
            ->count();
    }

    private function allergenConflictCount(int $unitId, string $date): int
    {
        if (! Schema::hasTable('special_menu_allergen_conflicts')) {
            return 0;
        }

        $query = DB::table('special_menu_allergen_conflicts')
            ->join('menus', 'menus.id', '=', 'special_menu_allergen_conflicts.menu_id')
            ->where('menus.sppg_unit_id', $unitId)
            ->whereDate('menus.service_date', $date);

        $columns = Schema::getColumnListing(
            'special_menu_allergen_conflicts'
        );

        if (in_array('is_resolved', $columns, true)) {
            $query->where('is_resolved', false);
        } elseif (in_array('status', $columns, true)) {
            $query->whereNotIn('status', ['resolved', 'closed']);
        }

        return $query->count();
    }

    private function openFindingsCount(int $unitId, string $date): int
    {
        if (! Schema::hasTable('field_incidents')) {
            return 0;
        }

        $query = DB::table('field_incidents')
            ->where('sppg_unit_id', $unitId);

        $columns = Schema::getColumnListing('field_incidents');

        foreach (['incident_date', 'reported_at', 'created_at'] as $column) {
            if (in_array($column, $columns, true)) {
                $query->whereDate($column, '<=', $date);
                break;
            }
        }

        if (in_array('status', $columns, true)) {
            $query->whereNotIn('status', [
                'resolved',
                'closed',
                'cancelled',
            ]);
        }

        return $query->count();
    }

    private function storeComponents(
        NutritionDailyReport $report,
        Menu $menu,
        int $plannedPortions,
        int $actualPortions,
    ): void {
        if (! Schema::hasTable('menu_nutrition_summaries')) {
            return;
        }

        $componentColumns = Schema::getColumnListing(
            'nutrition_components'
        );
        $nameColumn = in_array('name', $componentColumns, true)
            ? 'name'
            : 'code';
        $unitColumn = collect([
            'unit',
            'unit_symbol',
            'measurement_unit',
        ])->first(
            fn (string $column): bool =>
                in_array($column, $componentColumns, true)
        );

        $query = DB::table('menu_nutrition_summaries AS summaries')
            ->leftJoin(
                'nutrition_components AS components',
                'components.id',
                '=',
                'summaries.nutrition_component_id'
            )
            ->where('summaries.menu_id', $menu->getKey())
            ->groupBy(
                'summaries.nutrition_component_id',
                "components.{$nameColumn}"
            );

        if ($unitColumn) {
            $query->groupBy("components.{$unitColumn}");
        }

        $select = [
            'summaries.nutrition_component_id',
            DB::raw("components.{$nameColumn} AS component_name"),
            DB::raw('AVG(summaries.value_per_portion) AS value_per_portion'),
            DB::raw('AVG(summaries.standard_target) AS standard_target'),
        ];

        if ($unitColumn) {
            $select[] = DB::raw(
                "components.{$unitColumn} AS component_unit"
            );
        }

        $rows = $query->select($select)->get();

        foreach ($rows as $row) {
            $value = (float) $row->value_per_portion;
            $target = $row->standard_target !== null
                ? (float) $row->standard_target
                : null;

            $report->components()->create([
                'nutrition_component_id' =>
                    $row->nutrition_component_id,
                'component_name_snapshot' =>
                    $row->component_name ?? 'Komponen Gizi',
                'unit_snapshot' =>
                    $row->component_unit ?? null,
                'planned_per_portion' => round($value, 4),
                'actual_per_portion' => round($value, 4),
                'target_per_portion' =>
                    $target !== null ? round($target, 4) : null,
                'achievement_percent' =>
                    $target !== null && $target > 0
                        ? round(($value / $target) * 100, 2)
                        : null,
                'planned_total' => round(
                    $value * $plannedPortions,
                    4
                ),
                'actual_total' => round(
                    $value * $actualPortions,
                    4
                ),
            ]);
        }
    }
}
