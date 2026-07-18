<?php

namespace App\Enums;

enum PortioningSessionState: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case HandedOver = 'handed_over';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Direncanakan',
            self::InProgress => 'Sedang Diporsikan',
            self::Completed => 'Pemorsian Selesai',
            self::HandedOver => 'Diserahkan ke Distribusi',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::InProgress => 'warning',
            self::Completed => 'info',
            self::HandedOver => 'success',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $state): array => [$state->value => $state->label()])
            ->all();
    }
}
