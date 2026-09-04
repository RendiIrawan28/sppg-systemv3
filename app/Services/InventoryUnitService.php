<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\MeasurementUnit;

class InventoryUnitService
{
    /** Convert existing ledger snapshots without changing the stock engine or history. */
    public function stockCardQuantity(Ingredient $ingredient, ?string $sourceUnit, float $quantity, float $kilograms): ?float
    {
        $target = $ingredient->measurementUnit;
        $source = strtolower(trim((string) $sourceUnit));
        if ($quantity == 0 || ! $target || $source === $this->snapshot($target)) {
            return $quantity;
        }
        // Receipt records already preserve the received weight, including purchase packs/sacks.
        if ($target->unit_type === 'weight' && (float) $target->to_base_factor > 0 && $kilograms > 0) {
            return round($kilograms * 1000 / (float) $target->to_base_factor, 4);
        }
        $units = MeasurementUnit::query()->where('unit_type', $target->unit_type)->get()
            ->filter(fn ($unit) => in_array($source, array_map(fn ($v) => strtolower(trim((string) $v)), [$unit->symbol, $unit->code, $unit->name]), true));
        $factors = $units->pluck('to_base_factor')->unique();
        if ($factors->count() === 1 && (float) $factors->first() > 0 && (float) $target->to_base_factor > 0) {
            return round($quantity * (float) $factors->first() / (float) $target->to_base_factor, 4);
        }

        return null;
    }

    public function snapshot(MeasurementUnit $unit): string
    {
        return strtolower(trim((string) ($unit->symbol ?: $unit->code ?: $unit->name ?: 'unit')));
    }

    public function legacyKilograms(?Ingredient $ingredient, float $quantity): float
    {
        if (! $ingredient) {
            return 0;
        }

        $ingredient->loadMissing('measurementUnit');

        return $this->legacyKilogramsFromUnit($ingredient->measurementUnit, $quantity);
    }

    public function legacyKilogramsFromUnit(?MeasurementUnit $unit, float $quantity): float
    {
        if (! $unit || strtolower(trim((string) $unit->unit_type)) !== 'weight') {
            return 0;
        }

        $gramsPerUnit = (float) $unit->to_base_factor;

        return $gramsPerUnit > 0 ? round($quantity * $gramsPerUnit / 1000, 4) : 0;
    }
}
