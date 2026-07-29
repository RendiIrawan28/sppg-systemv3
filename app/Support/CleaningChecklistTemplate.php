<?php

namespace App\Support;

use App\Models\CleaningArea;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CleaningChecklistTemplate
{
    public const TOILET = 'toilet';
    public const PRODUCTION = 'production';
    public const PORTIONING = 'portioning';
    public const WAREHOUSE = 'warehouse';
    public const CUSTOM = 'custom';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::TOILET => 'Toilet',
            self::PRODUCTION => 'Area Produksi',
            self::PORTIONING => 'Area Pemorsian',
            self::WAREHOUSE => 'Gudang',
            self::CUSTOM => 'Checklist khusus',
        ];
    }

    /** @return array<int, array{category:string,item_name:string,is_mandatory:bool}> */
    public static function items(string|null $template): array
    {
        $names = match ($template) {
            self::TOILET => [
                'Lantai', 'Dinding', 'Atap', 'Pintu', 'Kloset', 'Ember',
                'Tissue', 'Tempat Sampah', 'Tersedia sabun',
            ],
            self::PRODUCTION => [
                'Lantai', 'Dinding', 'Langit-langit', 'Kompor', 'Meja',
                'Steamer', 'Jendela', 'Exhaust', 'Tidak Terdapat Serangga',
                'Tersedia Termometer',
            ],
            self::PORTIONING => [
                'Lantai', 'Dinding', 'Langit-langit', 'Kompor', 'Meja',
                'Jendela', 'Tidak Terdapat Serangga',
            ],
            self::WAREHOUSE => [
                'Lantai', 'Dinding', 'Langit-langit', 'Pintu', 'Rak', 'Pallet',
                'Tidak Terdapat Serangga', 'Tersedia Termometer',
            ],
            default => [],
        };

        return collect($names)
            ->map(fn (string $name): array => [
                'category' => $template ?: self::CUSTOM,
                'item_name' => $name,
                'is_mandatory' => true,
            ])
            ->values()
            ->all();
    }

    public static function forArea(CleaningArea $area): string
    {
        if (filled($area->template_type)) {
            return (string) $area->template_type;
        }

        $code = strtoupper((string) $area->code);
        $category = strtolower((string) $area->category);

        return match (true) {
            str_contains($code, 'TOILET') || str_contains($category, 'toilet') => self::TOILET,
            str_contains($code, 'PORSI') || str_contains($category, 'portion') => self::PORTIONING,
            str_contains($code, 'GUDANG') || in_array($category, ['storage', 'warehouse'], true) => self::WAREHOUSE,
            str_contains($code, 'DAPUR') || str_contains($code, 'PRODUKSI') || $category === 'production' => self::PRODUCTION,
            default => self::CUSTOM,
        };
    }

    public static function supportsPeriodExport(CleaningArea $area): bool
    {
        return in_array(self::forArea($area), [
            self::TOILET,
            self::PRODUCTION,
            self::PORTIONING,
            self::WAREHOUSE,
        ], true);
    }

    /** @return Collection<int, CarbonImmutable> */
    public static function workdays(
        CarbonInterface|string $start,
        CarbonInterface|string $end,
        int $limit = 10,
    ): Collection {
        $cursor = CarbonImmutable::parse($start)->startOfDay();
        $until = CarbonImmutable::parse($end)->startOfDay();
        $days = collect();

        while ($cursor->lte($until) && $days->count() < $limit) {
            if ($cursor->isWeekday()) {
                $days->push($cursor);
            }

            $cursor = $cursor->addDay();
        }

        return $days;
    }

    public static function areaIdentityLabel(CleaningArea $area): string
    {
        return match (self::forArea($area)) {
            self::TOILET => 'Toilet',
            self::WAREHOUSE => 'Gudang',
            default => 'Area',
        };
    }
}
