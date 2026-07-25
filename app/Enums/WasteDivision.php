<?php

namespace App\Enums;

enum WasteDivision: string
{
    case Washing = 'washing';
    case Cleaning = 'cleaning';

    public function label(): string
    {
        return match ($this) {
            self::Washing => 'Pencucian',
            self::Cleaning => 'Kebersihan',
        };
    }

    public function documentCode(): string
    {
        return match ($this) {
            self::Washing => 'PCN',
            self::Cleaning => 'KBR',
        };
    }
}
