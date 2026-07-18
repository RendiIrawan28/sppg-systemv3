<?php

namespace App\Enums;

enum MaterialCondition: string
{
    case Good = 'good';
    case Moderate = 'moderate';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Baik',
            self::Moderate => 'Sedang',
            self::Damaged => 'Rusak',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Good => 'success',
            self::Moderate => 'warning',
            self::Damaged => 'danger',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $condition): array => [
                $condition->value => $condition->label(),
            ])
            ->all();
    }
}
