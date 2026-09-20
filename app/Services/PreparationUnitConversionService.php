<?php

namespace App\Services;

use App\Models\MeasurementUnit;
use App\Models\PreparationSessionItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PreparationUnitConversionService
{
    /**
     * Satuan yang boleh dipilih untuk hasil Persiapan dan limbah.
     * Nilai yang disimpan berupa snapshot simbol/kode agar tetap stabil bila master berubah.
     *
     * @return array<string, string>
     */
    public function selectableUnits(): array
    {
        $defaults = collect([
            'kg' => 'Kilogram (kg)',
            'g' => 'Gram (g)',
        ]);

        $master = MeasurementUnit::query()
            ->where('is_active', true)
            ->orderBy('unit_type')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (MeasurementUnit $unit): array {
                $snapshot = $this->snapshot($unit);

                return [$snapshot => $unit->name.($snapshot !== $unit->name ? ' ('.$snapshot.')' : '')];
            });

        return $defaults->union($master)->all();
    }

    public function defaultResultUnit(?string $sourceUnit): string
    {
        $unit = $this->findUnit($sourceUnit);

        if ($unit && strtolower((string) $unit->unit_type) === 'weight') {
            return $this->snapshot($unit);
        }

        return 'kg';
    }

    /**
     * Menyimpan bobot kanonik (kg) tanpa mengubah angka/satuan yang diinput petugas.
     * Bobot kanonik inilah yang dipakai untuk rekonsiliasi lintas satuan.
     */
    public function normalizeItem(PreparationSessionItem $item): PreparationSessionItem
    {
        $item->loadMissing('ingredient.measurementUnit');

        $receivedQuantity = (float) ($item->received_quantity ?? 0);
        $receivedWeightKg = (float) ($item->received_weight_kg ?? 0);
        if ($receivedQuantity > 0 && $receivedWeightKg <= 0) {
            $receivedWeightKg = $this->inferSourceWeightKg($item, $receivedQuantity) ?? 0.0;
        }

        $processedQuantity = (float) ($item->processed_quantity ?? 0);
        $wasteQuantity = (float) ($item->waste_quantity ?? 0);
        $processedUnit = trim((string) ($item->processed_unit_snapshot ?: $item->unit_snapshot ?: 'kg'));
        $wasteUnit = trim((string) ($item->waste_unit_snapshot ?: $item->unit_snapshot ?: 'kg'));

        $cleanWeightKg = $processedQuantity > 0
            ? $this->quantityToKg($item, $processedQuantity, $processedUnit, $receivedWeightKg)
            : 0.0;
        $wasteWeightKg = $wasteQuantity > 0
            ? $this->quantityToKg($item, $wasteQuantity, $wasteUnit, $receivedWeightKg)
            : 0.0;

        $item->forceFill([
            'received_weight_kg' => round($receivedWeightKg, 4),
            'processed_unit_snapshot' => $processedUnit,
            'waste_unit_snapshot' => $wasteUnit,
            'clean_weight_kg' => round($cleanWeightKg, 4),
            'waste_weight_kg' => round($wasteWeightKg, 4),
        ])->save();

        return $item->refresh();
    }

    public function sourceQuantityToKg(PreparationSessionItem $item, float $quantity): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        $item->loadMissing('ingredient.measurementUnit');
        $receivedWeightKg = (float) ($item->received_weight_kg ?? 0);

        return $this->quantityToKg(
            $item,
            $quantity,
            (string) ($item->unit_snapshot ?: 'kg'),
            $receivedWeightKg,
        );
    }

    /**
     * @throws ValidationException bila satuan tidak dapat direkonsiliasi ke kg.
     */
    public function quantityToKg(
        PreparationSessionItem $item,
        float $quantity,
        ?string $unitSnapshot,
        ?float $receivedWeightKg = null,
    ): float {
        if ($quantity <= 0) {
            return 0.0;
        }

        $unitSnapshot = trim((string) $unitSnapshot);
        if ($unitSnapshot === '') {
            throw ValidationException::withMessages([
                'items' => "Satuan untuk {$item->ingredient_name_snapshot} belum dipilih.",
            ]);
        }

        if ($this->isKilogram($unitSnapshot)) {
            return round($quantity, 4);
        }
        if ($this->isGram($unitSnapshot)) {
            return round($quantity / 1000, 4);
        }

        $unit = $this->findUnit($unitSnapshot);
        if ($unit && strtolower((string) $unit->unit_type) === 'weight' && (float) $unit->to_base_factor > 0) {
            return round($quantity * (float) $unit->to_base_factor / 1000, 4);
        }

        $sourceUnit = $this->normalize((string) $item->unit_snapshot);
        if ($this->normalize($unitSnapshot) === $sourceUnit) {
            $receivedQuantity = (float) ($item->received_quantity ?? 0);
            $receivedWeightKg = (float) ($receivedWeightKg ?? $item->received_weight_kg ?? 0);
            if ($receivedQuantity > 0 && $receivedWeightKg > 0) {
                return round($quantity * ($receivedWeightKg / $receivedQuantity), 4);
            }

            $gramsPerUnit = (float) ($item->ingredient?->grams_per_unit ?? 0);
            if ($gramsPerUnit > 0) {
                return round($quantity * $gramsPerUnit / 1000, 4);
            }
        }

        throw ValidationException::withMessages([
            'items' => "Satuan {$unitSnapshot} pada {$item->ingredient_name_snapshot} belum memiliki konversi ke kg. Isi Bobot diterima aktual (kg), atau gunakan satuan berat seperti kg/g.",
        ]);
    }

    public function inferSourceWeightKg(PreparationSessionItem $item, ?float $quantity = null): ?float
    {
        $item->loadMissing('ingredient.measurementUnit');
        $quantity ??= (float) ($item->received_quantity ?? 0);
        if ($quantity <= 0) {
            return 0.0;
        }

        $source = trim((string) ($item->unit_snapshot ?: 'kg'));
        if ($this->isKilogram($source)) {
            return round($quantity, 4);
        }
        if ($this->isGram($source)) {
            return round($quantity / 1000, 4);
        }

        $unit = $this->findUnit($source);
        if ($unit && strtolower((string) $unit->unit_type) === 'weight' && (float) $unit->to_base_factor > 0) {
            return round($quantity * (float) $unit->to_base_factor / 1000, 4);
        }

        $gramsPerUnit = (float) ($item->ingredient?->grams_per_unit ?? 0);
        if ($gramsPerUnit > 0) {
            return round($quantity * $gramsPerUnit / 1000, 4);
        }

        return null;
    }

    public function isSourceWeightUnit(PreparationSessionItem $item): bool
    {
        $unit = $this->findUnit($item->unit_snapshot);

        return $this->isKilogram($item->unit_snapshot)
            || $this->isGram($item->unit_snapshot)
            || ($unit && strtolower((string) $unit->unit_type) === 'weight');
    }

    private function findUnit(?string $snapshot): ?MeasurementUnit
    {
        $needle = $this->normalize((string) $snapshot);
        if ($needle === '') {
            return null;
        }

        return $this->measurementUnits()->first(function (MeasurementUnit $unit) use ($needle): bool {
            return in_array($needle, [
                $this->normalize((string) $unit->symbol),
                $this->normalize((string) $unit->code),
                $this->normalize((string) $unit->name),
            ], true);
        });
    }

    /** @return Collection<int, MeasurementUnit> */
    private function measurementUnits(): Collection
    {
        return MeasurementUnit::query()->where('is_active', true)->get();
    }

    private function snapshot(MeasurementUnit $unit): string
    {
        return trim((string) ($unit->symbol ?: $unit->code ?: $unit->name ?: 'unit'));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function isKilogram(?string $unit): bool
    {
        return in_array($this->normalize((string) $unit), ['kg', 'kilogram'], true);
    }

    private function isGram(?string $unit): bool
    {
        return in_array($this->normalize((string) $unit), ['g', 'gr', 'gram'], true);
    }
}
