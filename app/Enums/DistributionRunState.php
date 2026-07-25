<?php

namespace App\Enums;

enum DistributionRunState: string
{
    case Planned = 'planned';
    case Loaded = 'loaded';
    case Departed = 'departed';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Direncanakan',
            self::Loaded => 'Sedang Memuat',
            self::Departed => 'Dalam Distribusi',
            self::Returned => 'Kembali ke SPPG',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Loaded => 'info',
            self::Departed => 'warning',
            self::Returned => 'success',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $state): array => [$state->value => $state->label()])
            ->all();
    }
}
