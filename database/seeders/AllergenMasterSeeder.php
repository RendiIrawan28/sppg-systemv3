<?php

namespace Database\Seeders;

use App\Models\Allergen;
use Illuminate\Database\Seeder;

class AllergenMasterSeeder extends Seeder
{
    public function run(): void
    {
        foreach (MbgV14NutritionSeeder::allergenRows() as $item) {
            Allergen::query()->updateOrCreate(
                ['code' => $item['code']],
                [
                    'name' => $item['name'],
                    'description' => '[MBG_V14_SEED] Master jenis alergen bahan/menu.',
                    'sort_order' => $item['sort_order'],
                    'is_active' => true,
                ],
            );
        }
    }
}
