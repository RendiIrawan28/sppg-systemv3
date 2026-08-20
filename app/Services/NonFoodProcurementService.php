<?php

namespace App\Services;

use App\Models\NonFoodItem;
use App\Models\ProcurementRequest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NonFoodProcurementService
{
    /** @param array<int|string, float|int|string> $quantities */
    public function createDraft(int $unitId, string $neededDate, array $quantities, ?string $notes, User $actor): ProcurementRequest
    {
        abort_unless($actor->can('non_food_procurement.create'), 403);
        $warehouse = Warehouse::forUnit($unitId, Warehouse::TYPE_NON_FOOD);
        $selected = collect($quantities)
            ->map(fn ($quantity): float => (float) $quantity)
            ->filter(fn (float $quantity): bool => $quantity > 0);
        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['quantities' => 'Isi minimal satu jumlah pengadaan Non-Pangan.']);
        }

        $items = NonFoodItem::query()
            ->with('measurementUnit')
            ->forUnit($unitId)
            ->where('is_active', true)
            ->whereIn('id', $selected->keys()->map(fn ($id): int => (int) $id))
            ->get()
            ->keyBy('id');
        if ($items->count() !== $selected->count()) {
            throw ValidationException::withMessages(['quantities' => 'Salah satu barang Non-Pangan tidak valid.']);
        }

        return DB::transaction(function () use ($unitId, $neededDate, $notes, $actor, $warehouse, $selected, $items): ProcurementRequest {
            $request = ProcurementRequest::query()->create([
                'sppg_unit_id' => $unitId,
                'warehouse_id' => $warehouse->getKey(),
                'procurement_type' => Warehouse::TYPE_NON_FOOD,
                'request_date' => today(),
                'needed_date' => $neededDate,
                'status' => ProcurementRequest::STATUS_DRAFT,
                'price_status' => 'draft',
                'notes' => trim((string) $notes) ?: 'Kebutuhan operasional Gudang Non-Pangan.',
                'created_by' => $actor->getKey(),
            ]);

            foreach ($selected as $id => $quantity) {
                $item = $items->get((int) $id);
                $unit = $item->measurementUnit;
                $suggested = $item->suggestedPurchaseQuantity();
                $request->items()->create([
                    'non_food_item_id' => $item->getKey(),
                    'ingredient_code_snapshot' => $item->code,
                    'ingredient_name_snapshot' => $item->name,
                    'unit_snapshot' => $unit?->symbol ?: $unit?->code ?: 'unit',
                    'measurement_unit_id' => $item->measurement_unit_id,
                    'requested_quantity' => $quantity,
                    'approved_quantity' => $quantity,
                    'requested_quantity_kg' => 0,
                    'approved_quantity_kg' => 0,
                    'estimated_unit_price' => 0,
                    'estimated_total_price' => 0,
                    'notes' => abs($quantity - $suggested) > 0.0001
                        ? 'Jumlah manual berbeda dari saran sistem '.number_format($suggested, 4, ',', '.').'.'
                        : 'Mengikuti saran tambah sistem.',
                ]);
            }

            app(ProcurementRequestService::class)->recalculate($request);
            return $request->refresh()->load('items.nonFoodItem');
        });
    }
}
