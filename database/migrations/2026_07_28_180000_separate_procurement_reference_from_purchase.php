<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('procurement_request_items')) {
            return;
        }

        $addRequirementQuantity = ! Schema::hasColumn('procurement_request_items', 'requirement_quantity_snapshot');
        $addRequirementUnit = ! Schema::hasColumn('procurement_request_items', 'requirement_unit_snapshot');

        if ($addRequirementQuantity || $addRequirementUnit) {
            Schema::table('procurement_request_items', function (Blueprint $table) use ($addRequirementQuantity, $addRequirementUnit): void {
                if ($addRequirementQuantity) {
                    $table->decimal('requirement_quantity_snapshot', 14, 4)
                        ->nullable()
                        ->after('kg_per_unit_snapshot');
                }

                if ($addRequirementUnit) {
                    $table->string('requirement_unit_snapshot', 80)
                        ->nullable()
                        ->after('requirement_quantity_snapshot');
                }
            });
        }

        $units = Schema::hasTable('measurement_units')
            ? DB::table('measurement_units')->select(['id', 'code', 'symbol'])->get()->keyBy('id')
            : collect();

        DB::table('procurement_request_items')
            ->select([
                'id',
                'nutrition_requirement_item_id',
                'measurement_unit_id',
                'unit_snapshot',
                'requested_quantity',
                'approved_quantity',
                'requested_quantity_kg',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($units): void {
                foreach ($items as $item) {
                    $requirement = null;

                    if ($item->nutrition_requirement_item_id
                        && Schema::hasTable('nutrition_requirement_items')) {
                        $requirement = DB::table('nutrition_requirement_items')
                            ->select(['total_quantity', 'total_quantity_kg', 'unit_snapshot'])
                            ->where('id', $item->nutrition_requirement_item_id)
                            ->first();
                    }

                    $requirementQuantity = null;
                    $requirementUnit = null;

                    if ($requirement) {
                        $requirementQuantity = (float) ($requirement->total_quantity ?? 0);
                        $requirementUnit = trim((string) ($requirement->unit_snapshot ?? ''));

                        if ($requirementQuantity <= 0) {
                            $requirementQuantity = (float) ($requirement->total_quantity_kg ?? 0);
                            $requirementUnit = 'kg';
                        }
                    } elseif ($item->nutrition_requirement_item_id && (float) $item->requested_quantity_kg > 0) {
                        $requirementQuantity = (float) $item->requested_quantity_kg;
                        $requirementUnit = 'kg';
                    }

                    $measurementUnit = $item->measurement_unit_id
                        ? $units->get($item->measurement_unit_id)
                        : null;

                    $code = strtolower(trim((string) ($measurementUnit?->code ?? '')));
                    $symbol = strtolower(trim((string) ($measurementUnit?->symbol ?? $item->unit_snapshot ?? '')));
                    $isKilogram = in_array($code, ['kg', 'kilogram'], true)
                        || in_array($symbol, ['kg', 'kilogram'], true);

                    $requestedQuantity = (float) ($item->requested_quantity ?? 0);
                    $approvedQuantity = (float) ($item->approved_quantity ?? $requestedQuantity);

                    DB::table('procurement_request_items')
                        ->where('id', $item->id)
                        ->update([
                            'requirement_quantity_snapshot' => $requirementQuantity > 0
                                ? round($requirementQuantity, 4)
                                : null,
                            'requirement_unit_snapshot' => $requirementUnit !== ''
                                ? $requirementUnit
                                : null,
                            'kg_per_unit_snapshot' => null,
                            'requested_quantity_kg' => $isKilogram
                                ? round($requestedQuantity, 4)
                                : 0,
                            'approved_quantity_kg' => $isKilogram
                                ? round($approvedQuantity, 4)
                                : 0,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('procurement_request_items')) {
            return;
        }

        Schema::table('procurement_request_items', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('procurement_request_items', 'requirement_quantity_snapshot')) {
                $columns[] = 'requirement_quantity_snapshot';
            }

            if (Schema::hasColumn('procurement_request_items', 'requirement_unit_snapshot')) {
                $columns[] = 'requirement_unit_snapshot';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
