<?php

namespace App\Services;

use InvalidArgumentException;

class NutritionPortionAllocator
{
    /**
     * @param array<int, array<string, mixed>> $groups
     * @param array<int, array<string, mixed>> $targets
     * @return array<int, array<string, mixed>>
     */
    public function allocate(array $groups, array $targets): array
    {
        $targetsById = [];
        $targetsByCode = [];
        $targetsByAudienceAndSize = [];

        foreach ($targets as $target) {
            $target = $this->normalizeTarget($target);

            if ($target['beneficiary_category_id'] > 0) {
                $targetsById[$target['beneficiary_category_id']] = $target;
            }

            if ($target['code'] !== '') {
                $targetsByCode[$target['code']] = $target;
            }

            $combo = $this->comboKey(
                $target['menu_audience'],
                $target['portion_size'],
            );

            $targetsByAudienceAndSize[$combo] ??= [];
            $targetsByAudienceAndSize[$combo][] = $target;
        }

        $allocations = [];
        $unmatched = [];

        foreach ($groups as $group) {
            $group = $this->normalizeGroup($group);

            if ($group['actual_portions'] <= 0) {
                continue;
            }

            $target = null;

            if ($group['beneficiary_category_id'] > 0) {
                $target = $targetsById[$group['beneficiary_category_id']] ?? null;
            }

            if (! $target && $group['code'] !== '') {
                $target = $targetsByCode[$group['code']] ?? null;
            }

            if (! $target) {
                $comboTargets = $targetsByAudienceAndSize[
                    $this->comboKey(
                        $group['menu_audience'],
                        $group['portion_size'],
                    )
                ] ?? [];

                if (count($comboTargets) === 1) {
                    $target = $comboTargets[0];
                }
            }

            if (! $target) {
                $unmatched[] = $group['name'] !== ''
                    ? $group['name']
                    : ($group['code'] !== '' ? $group['code'] : 'Kelompok tanpa nama');
                continue;
            }

            $multiplier = (float) $target['multiplier'];

            if (! is_finite($multiplier) || $multiplier <= 0) {
                throw new InvalidArgumentException(
                    "Pengali porsi untuk {$target['name']} harus lebih dari nol."
                );
            }

            $allocations[] = [
                'beneficiary_category_id' => $group['beneficiary_category_id'] ?: $target['beneficiary_category_id'],
                'code' => $group['code'] !== '' ? $group['code'] : $target['code'],
                'name' => $group['name'] !== '' ? $group['name'] : $target['name'],
                'menu_audience' => $group['menu_audience'] !== ''
                    ? $group['menu_audience']
                    : $target['menu_audience'],
                'portion_size' => $group['portion_size'] !== ''
                    ? $group['portion_size']
                    : $target['portion_size'],
                'actual_portions' => $group['actual_portions'],
                'portion_multiplier' => round($multiplier, 4),
                'effective_portions' => round(
                    $group['actual_portions'] * $multiplier,
                    4,
                ),
            ];
        }

        if ($unmatched !== []) {
            throw new InvalidArgumentException(
                'Menu belum memiliki target kategori untuk: '.implode(', ', array_unique($unmatched)).'.'
            );
        }

        if ($allocations === []) {
            throw new InvalidArgumentException(
                'Tidak ada jumlah aktual penerima yang dapat digunakan untuk menghitung kebutuhan bahan.'
            );
        }

        return $allocations;
    }

    /** @param array<string, mixed> $target */
    private function normalizeTarget(array $target): array
    {
        return [
            'beneficiary_category_id' => (int) ($target['beneficiary_category_id'] ?? 0),
            'code' => $this->normalizeCode((string) ($target['code'] ?? '')),
            'name' => trim((string) ($target['name'] ?? 'Kategori penerima')),
            'menu_audience' => $this->normalizeCode((string) ($target['menu_audience'] ?? '')),
            'portion_size' => $this->normalizeCode((string) ($target['portion_size'] ?? '')),
            'multiplier' => (float) ($target['multiplier'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $group */
    private function normalizeGroup(array $group): array
    {
        return [
            'beneficiary_category_id' => (int) ($group['beneficiary_category_id'] ?? 0),
            'code' => $this->normalizeCode((string) ($group['code'] ?? '')),
            'name' => trim((string) ($group['name'] ?? '')),
            'menu_audience' => $this->normalizeCode((string) ($group['menu_audience'] ?? '')),
            'portion_size' => $this->normalizeCode((string) ($group['portion_size'] ?? '')),
            'actual_portions' => max(0, (int) ($group['actual_portions'] ?? 0)),
        ];
    }

    private function normalizeCode(string $value): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', $value)));
    }

    private function comboKey(string $audience, string $portionSize): string
    {
        return $this->normalizeCode($audience).'|'.$this->normalizeCode($portionSize);
    }
}
