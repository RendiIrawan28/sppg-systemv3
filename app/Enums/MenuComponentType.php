<?php

namespace App\Enums;

enum MenuComponentType: string
{
    case Staple = 'staple';
    case AnimalProtein = 'animal_protein';
    case PlantProtein = 'plant_protein';
    case Vegetable = 'vegetable';
    case Fruit = 'fruit';
    case Milk = 'milk';

    public function label(): string
    {
        return match ($this) {
            self::Staple => 'Karbohidrat / Makanan Pokok',
            self::AnimalProtein => 'Protein Hewani',
            self::PlantProtein => 'Protein Nabati (minimal salah satu dengan Susu)',
            self::Vegetable => 'Sayur',
            self::Fruit => 'Buah',
            self::Milk => 'Susu (minimal salah satu dengan Protein Nabati)',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $item): array => [$item->value => $item->label()])
            ->all();
    }
}
