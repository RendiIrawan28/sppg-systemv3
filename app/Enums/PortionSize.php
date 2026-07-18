<?php

namespace App\Enums;

enum PortionSize: string
{
    case Small = 'small';
    case Large = 'large';

    public function label(): string
    {
        return match ($this) {
            self::Small => 'Porsi Kecil',
            self::Large => 'Porsi Besar',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $size): array => [$size->value => $size->label()])
            ->all();
    }
}
