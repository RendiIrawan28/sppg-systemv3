<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class NutritionMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->warn(
            'NutritionMasterSeeder sudah digabung ke MbgV14NutritionSeeder agar satuan dan komponen gizi tidak tabrakan. Jalankan MbgV14NutritionSeeder.'
        );
    }
}
