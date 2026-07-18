<?php

namespace App\Support\V3;

use App\Models\SppgUnit;
use Illuminate\Database\Eloquent\Model;

final class SystemUnit
{
    public function get(): ?SppgUnit
    {
        return SppgUnit::query()
            ->where('is_active', true)
            ->when(config('sppg.unit_id'), fn ($query, $unitId) => $query->whereKey($unitId))
            ->orderBy('id')
            ->first();
    }

    public function id(): ?int
    {
        return $this->get()?->getKey();
    }

    public function owns(Model|int|null $recordOrUnitId): bool
    {
        $unitId = $recordOrUnitId instanceof Model
            ? $recordOrUnitId->getAttribute('sppg_unit_id')
            : $recordOrUnitId;

        return $unitId !== null && (int) $unitId === (int) $this->id();
    }
}
