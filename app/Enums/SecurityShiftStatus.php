<?php

namespace App\Enums;

enum SecurityShiftStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Sedang Bertugas',
            self::Completed => 'Selesai',
            self::Expired => 'Selesai Otomatis',
        };
    }
}
