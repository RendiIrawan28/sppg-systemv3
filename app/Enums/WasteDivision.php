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

    public function permissionPrefix(): string
    {
        return match ($this) {
            self::Preparation => 'preparation',
            self::Washing => 'washing',
            self::Cleaning => 'cleaning',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $division): array => [$division->value => $division->label()])
            ->all();
    }
}
