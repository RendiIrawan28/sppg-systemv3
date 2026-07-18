<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class NutritionMenuMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn(
            'NutritionMenuMasterSeeder sudah tidak dipakai sebagai seeder default. Master menu audience, guru/tendik, dan maternal dinormalisasi oleh BeneficiaryCategorySeeder + MbgV14NutritionSeeder.'
        );
    }
}
