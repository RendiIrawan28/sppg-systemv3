<?php

namespace App\Enums;

enum MenuPortionProfile: string
{
    case Small = 'small';
    case Large = 'large';
    case Toddler = 'toddler';
    case Maternal = 'maternal';

    public function label(): string
    {
        return match ($this) {
            self::Small => 'Porsi Kecil',
            self::Large => 'Porsi Besar',
            self::Toddler => 'Balita',
            self::Maternal => 'Bumil/Busui',
        };
    }

    public function recipeColumn(): string
    {
        return match ($this) {
            self::Small => 'quantity_small_grams',
            self::Large => 'quantity_large_grams',
            self::Toddler => 'quantity_toddler_grams',
            self::Maternal => 'quantity_maternal_grams',
        };
    }

    public function portionWeightColumn(): string
    {
        return match ($this) {
            self::Small => 'portion_weight_small_grams',
            self::Large => 'portion_weight_large_grams',
            self::Toddler => 'portion_weight_toddler_grams',
            self::Maternal => 'portion_weight_maternal_grams',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $item): array => [$item->value => $item->label()])
            ->all();
    }
}
