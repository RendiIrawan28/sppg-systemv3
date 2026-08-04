<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\MeasurementUnit;

class InventoryUnitService
{
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
