<?php

namespace App\Enums;

enum DistributionRunState: string
{
    case Planned = 'planned';
    case Assigned = 'assigned';
    case Loaded = 'loaded';
    case Departed = 'departed';
    case DestinationsCompleted = 'destinations_completed';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Tersedia',
            self::Assigned => 'Dipilih Driver',
            self::Loaded => 'Memuat',
            self::Departed => 'Dalam Pengantaran',
            self::DestinationsCompleted => 'Semua Tujuan Selesai',
            self::Returned => 'Kembali ke SPPG',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::Assigned => 'info',
            self::Loaded => 'info',
            self::Departed => 'warning',
            self::DestinationsCompleted => 'warning',
            self::Returned => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function isActiveForDriver(): bool
    {
        return in_array($this, [
            self::Assigned,
            self::Loaded,
            self::Departed,
            self::DestinationsCompleted,
        ], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $state): array => [$state->value => $state->label()])
            ->all();
    }
}
