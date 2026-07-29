<?php

namespace App\Enums;

enum CleaningSessionState: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Menunggu',
            self::InProgress => 'Sedang Dibersihkan',
            self::Completed => 'Pembersihan Selesai (lama)',
            self::Ready => 'Selesai',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::InProgress => 'warning',
            self::Completed => 'info',
            self::Ready => 'success',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $state): array => [$state->value => $state->label()])
            ->all();
    }
}
