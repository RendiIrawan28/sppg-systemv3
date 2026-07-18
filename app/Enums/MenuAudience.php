<?php

namespace App\Enums;

enum MenuAudience: string
{
    case All = 'all';
    case Student = 'student';
    case Toddler = 'toddler';
    case Maternal = 'maternal';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Semua Kelompok',
            self::Student => 'Siswa, Guru, dan Tendik',
            self::Toddler => 'Balita',
            self::Maternal => 'Bumil/Busui',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $item): array => [$item->value => $item->label()])
            ->all();
    }
}
