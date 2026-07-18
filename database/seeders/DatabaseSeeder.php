<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AccessControlSeeder::class,
            BeneficiaryCategorySeeder::class,
            MbgV14NutritionSeeder::class,
            CleaningAreaSeeder::class,
            EmployeeUserSeeder::class,
        ]);
    }
}
