<?php

namespace App\Enums;

enum ProcessingBatchState: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Direncanakan',
            self::InProgress => 'Sedang Diproses',
            self::Completed => 'Produksi Selesai',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Planned => 'gray',
            self::InProgress => 'warning',
            self::Completed => 'info',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $state): array => [
                $state->value => $state->label(),
            ])
            ->all();
    }
}
