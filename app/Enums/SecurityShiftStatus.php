<?php

namespace App\Enums;

enum SecurityShiftStatus: string
{
    case Active = 'active';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Sedang Bertugas',
            self::Completed => 'Selesai',
        };
    }
}
