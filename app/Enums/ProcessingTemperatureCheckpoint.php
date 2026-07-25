<?php

namespace App\Enums;

enum ProcessingTemperatureCheckpoint: string
{
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Final => 'Setelah Makanan Matang',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $checkpoint): array => [
                $checkpoint->value => $checkpoint->label(),
            ])
            ->all();
    }
}
