<?php

namespace App\Enums;

enum WasteDivision: string
{
    case Preparation = 'preparation';
    case Washing = 'washing';
    case Cleaning = 'cleaning';

    public function label(): string
    {
        return match ($this) {
            self::Preparation => 'Persiapan',
            self::Washing => 'Pencucian',
            self::Cleaning => 'Kebersihan',
        };
    }

    public function documentCode(): string
    {
        return match ($this) {
            self::Preparation => 'PRS',
            self::Washing => 'PCN',
            self::Cleaning => 'KBR',
        };
    }
}
