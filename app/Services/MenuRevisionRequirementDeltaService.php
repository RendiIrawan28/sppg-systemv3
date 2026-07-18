<?php

namespace App\Services;

use App\Enums\NutritionRecordStatus;
use App\Models\FieldDistributionPlan;
use App\Models\MenuDayRevisionRequest;
use App\Models\NutritionRequirementItem;
use App\Models\NutritionRequirementPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MenuRevisionRequirementDeltaService
{
    public function __construct(
        private readonly NutritionRequirementCalculator $calculator,
    ) {
    }

    public function createForCompletedRevision(MenuDayRevisionRequest $request, User $actor): ?NutritionRequirementPlan
    {
        $request->loadMissing(['day.fieldDistributionPlan', 'originalMenu', 'revisionMenu']);
        $day = $request->day;
        $fieldPlan = $day?->fieldDistributionPlan;

        if (! $day || ! $fieldPlan || ! $request->revisionMenu) {
            return null;
        }

        return DB::transaction(function () use ($request, $fieldPlan, $actor): ?NutritionRequirementPlan {
            $fieldPlan = FieldDistributionPlan::query()
                ->lockForUpdate()
                ->findOrFail($fieldPlan->getKey());

            $oldRequirement = $this->latestOperationalRequirement($fieldPlan);

            $fieldPlan->forceFill([
                'menu_id' => $request->revision_menu_id,
                'menu_name_snapshot' => $request->revisionMenu?->name ?: $fieldPlan->menu_name_snapshot,
                'updated_by' => $actor->getKey(),
            ])->save();

            if (! $oldRequirement) {
                return null;
            }

            $newSnapshot = $this->makeRequirementSnapshot(
                fieldPlan: $fieldPlan->refresh(),
                request: $request,
                actor: $actor,
                type: 'revision_recalculation',
                status: NutritionRecordStatus::Archived,
            );

            $adjustment = $this->makeAdjustmentPlan($oldRequirement, $newSnapshot, $request, $actor);

            return $adjustment?->refresh();
        });
    }

    private function latestOperationalRequirement(FieldDistributionPlan $fieldPlan): ?NutritionRequirementPlan
    {
        return NutritionRequirementPlan::query()
            ->with('items')
            ->where('field_distribution_plan_id', $fieldPlan->getKey())
            ->where(function ($query): void {
                if (Schema::hasColumn('nutrition_requirement_plans', 'requirement_type')) {
                    $query->whereNull('requirement_type')
                        ->orWhere('requirement_type', 'regular');
                }
            })
            ->latest('id')
            ->first();
    }

    private function makeRequirementSnapshot(
        FieldDistributionPlan $fieldPlan,
        MenuDayRevisionRequest $request,
        User $actor,
        string $type,
        NutritionRecordStatus $status,
    ): NutritionRequirementPlan {
        $snapshot = new NutritionRequirementPlan();
        $snapshot->forceFill([
            'sppg_unit_id' => $fieldPlan->sppg_unit_id,
            'requirement_date' => $fieldPlan->distribution_date,
            'menu_id' => $fieldPlan->menu_id,
            'field_distribution_plan_id' => $fieldPlan->getKey(),
            'total_portions' => (int) $fieldPlan->planned_total_portions,
            'buffer_percent' => (float) config('nutrition_menu.default_buffer_percent', 2),
            'status' => NutritionRecordStatus::Draft,
            'notes' => 'Snapshot perhitungan ulang akibat revisi menu hari pelayanan. Tidak digunakan sebagai permintaan bahan langsung.',
            'created_by' => $actor->getKey(),
        ]);

        $this->fillOptionalAdjustmentColumns($snapshot, $type, $request, null);
        $snapshot->save();
        $this->calculator->generate($snapshot->refresh());

        $snapshot->forceFill([
            'status' => $status,
            'notes' => trim(($snapshot->notes ? $snapshot->notes."\n" : '').'Diarsipkan otomatis setelah menghasilkan rencana selisih bahan.'),
        ])->save();

        return $snapshot->refresh();
    }

    private function makeAdjustmentPlan(
        NutritionRequirementPlan $old,
        NutritionRequirementPlan $new,
        MenuDayRevisionRequest $request,
        User $actor,
    ): ?NutritionRequirementPlan {
        $deltas = $this->calculateDeltas($old->loadMissing('items'), $new->loadMissing('items'));

        if ($deltas === []) {
            return null;
        }

        $adjustment = new NutritionRequirementPlan();
        $adjustment->forceFill([
            'sppg_unit_id' => $old->sppg_unit_id,
            'requirement_date' => $old->requirement_date,
            'menu_id' => $new->menu_id,
            'field_distribution_plan_id' => $old->field_distribution_plan_id,
            'total_portions' => 0,
            'buffer_percent' => 0,
            'total_items' => count($deltas),
            'total_weight_kg' => array_sum(array_map(fn (array $row): float => (float) $row['delta_kg'], $deltas)),
            'status' => NutritionRecordStatus::Draft,
            'notes' => 'Selisih kebutuhan bahan otomatis akibat revisi menu aktif. Nilai positif berarti tambahan kebutuhan; nilai negatif berarti pengurangan/kelebihan yang harus disesuaikan Gudang.',
            'created_by' => $actor->getKey(),
            'generated_at' => now(),
        ]);

        $this->fillOptionalAdjustmentColumns($adjustment, 'revision_adjustment', $request, $old);
        $adjustment->save();

        foreach ($deltas as $row) {
            $adjustment->items()->create([
                'ingredient_id' => $row['ingredient_id'],
                'ingredient_code_snapshot' => $row['code'],
                'ingredient_name_snapshot' => $row['name'],
                'unit_snapshot' => $row['unit'],
                'quantity_per_portion_grams' => 0,
                'effective_portions' => 0,
                'base_quantity_grams' => $row['delta_grams'],
                'buffer_percent' => 0,
                'total_quantity_grams' => $row['delta_grams'],
                'total_quantity_kg' => $row['delta_kg'],
                'recipe_components' => 'Selisih revisi menu aktif',
                'calculation_breakdown' => [
                    'old_requirement_plan_id' => $old->getKey(),
                    'new_snapshot_requirement_plan_id' => $new->getKey(),
                    'old_quantity_kg' => $row['old_kg'],
                    'new_quantity_kg' => $row['new_kg'],
                    'delta_kg' => $row['delta_kg'],
                ],
                'notes' => $row['delta_kg'] >= 0
                    ? 'Tambahan kebutuhan bahan akibat revisi menu.'
                    : 'Pengurangan kebutuhan bahan akibat revisi menu.',
            ]);
        }

        return $adjustment->refresh();
    }

    /** @return array<int, array<string, mixed>> */
    private function calculateDeltas(NutritionRequirementPlan $old, NutritionRequirementPlan $new): array
    {
        $oldRows = $this->indexItems($old);
        $newRows = $this->indexItems($new);
        $keys = array_values(array_unique([...array_keys($oldRows), ...array_keys($newRows)]));
        $deltas = [];

        foreach ($keys as $key) {
            $oldRow = $oldRows[$key] ?? null;
            $newRow = $newRows[$key] ?? null;
            $oldKg = (float) ($oldRow?->total_quantity_kg ?? 0);
            $newKg = (float) ($newRow?->total_quantity_kg ?? 0);
            $deltaKg = round($newKg - $oldKg, 4);

            if (abs($deltaKg) < 0.0001) {
                continue;
            }

            $source = $newRow ?: $oldRow;

            $deltas[] = [
                'ingredient_id' => $source?->ingredient_id,
                'code' => $source?->ingredient_code_snapshot,
                'name' => $source?->ingredient_name_snapshot ?: 'Bahan tidak diketahui',
                'unit' => $source?->unit_snapshot ?: 'kg',
                'old_kg' => $oldKg,
                'new_kg' => $newKg,
                'delta_kg' => $deltaKg,
                'delta_grams' => round($deltaKg * 1000, 4),
            ];
        }

        return $deltas;
    }

    /** @return array<string, NutritionRequirementItem> */
    private function indexItems(NutritionRequirementPlan $plan): array
    {
        $rows = [];

        foreach ($plan->items as $item) {
            $key = $item->ingredient_id
                ? 'id:'.$item->ingredient_id
                : 'name:'.mb_strtolower((string) $item->ingredient_name_snapshot);

            $rows[$key] = $item;
        }

        return $rows;
    }

    private function fillOptionalAdjustmentColumns(
        NutritionRequirementPlan $plan,
        string $type,
        MenuDayRevisionRequest $request,
        ?NutritionRequirementPlan $original,
    ): void {
        $attributes = [];

        if (Schema::hasColumn('nutrition_requirement_plans', 'requirement_type')) {
            $attributes['requirement_type'] = $type;
        }

        if (Schema::hasColumn('nutrition_requirement_plans', 'original_requirement_plan_id')) {
            $attributes['original_requirement_plan_id'] = $original?->getKey();
        }

        if (Schema::hasColumn('nutrition_requirement_plans', 'menu_day_revision_request_id')) {
            $attributes['menu_day_revision_request_id'] = $request->getKey();
        }

        if (Schema::hasColumn('nutrition_requirement_plans', 'adjustment_generated_at')) {
            $attributes['adjustment_generated_at'] = now();
        }

        if (Schema::hasColumn('nutrition_requirement_plans', 'adjustment_notes')) {
            $attributes['adjustment_notes'] = $request->impact_notes ?: $request->reason;
        }

        $plan->forceFill($attributes);
    }
}
