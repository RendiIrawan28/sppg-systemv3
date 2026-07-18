<?php

namespace Database\Seeders;

use App\Models\BeneficiaryCategory;
use App\Models\SppgUnit;
use Illuminate\Database\Seeder;

class BeneficiaryCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'code' => 'paud',
                'name' => 'PAUD',
                'group_type' => 'education',
                'education_level' => 'paud',
                'portion_size' => 'small',
                'menu_audience' => 'student',
                'sort_order' => 1,
            ],
            [
                'code' => 'tk',
                'name' => 'TK',
                'group_type' => 'education',
                'education_level' => 'tk',
                'portion_size' => 'small',
                'menu_audience' => 'student',
                'sort_order' => 2,
            ],
            [
                'code' => 'sd_1_3',
                'name' => 'SD Kelas 1–3',
                'group_type' => 'education',
                'education_level' => 'sd',
                'grade_start' => 1,
                'grade_end' => 3,
                'portion_size' => 'small',
                'menu_audience' => 'student',
                'sort_order' => 3,
            ],
            [
                'code' => 'sd_4_6',
                'name' => 'SD Kelas 4–6',
                'group_type' => 'education',
                'education_level' => 'sd',
                'grade_start' => 4,
                'grade_end' => 6,
                'portion_size' => 'large',
                'menu_audience' => 'student',
                'sort_order' => 4,
            ],
            [
                'code' => 'smp',
                'name' => 'SMP',
                'group_type' => 'education',
                'education_level' => 'smp',
                'portion_size' => 'large',
                'menu_audience' => 'student',
                'sort_order' => 5,
            ],
            [
                'code' => 'sma',
                'name' => 'SMA',
                'group_type' => 'education',
                'education_level' => 'sma',
                'portion_size' => 'large',
                'menu_audience' => 'student',
                'sort_order' => 6,
            ],
            [
                'code' => 'balita',
                'name' => 'Balita',
                'group_type' => 'b3',
                'portion_size' => 'small',
                'menu_audience' => 'toddler',
                'sort_order' => 7,
            ],
            [
                'code' => 'ibu_hamil',
                'name' => 'Ibu Hamil',
                'group_type' => 'b3',
                'portion_size' => 'large',
                'menu_audience' => 'maternal',
                'sort_order' => 8,
            ],
            [
                'code' => 'ibu_menyusui',
                'name' => 'Ibu Menyusui',
                'group_type' => 'b3',
                'portion_size' => 'large',
                'menu_audience' => 'maternal',
                'sort_order' => 9,
            ],
            [
                'code' => 'lainnya',
                'name' => 'Lainnya',
                'group_type' => 'other',
                'portion_size' => 'small',
                'menu_audience' => 'student',
                'sort_order' => 99,
            ],
        ];

        SppgUnit::query()
            ->where('is_active', true)
            ->each(function (SppgUnit $unit) use ($categories): void {
                foreach ($categories as $category) {
                    BeneficiaryCategory::query()->updateOrCreate(
                        [
                            'sppg_unit_id' => $unit->getKey(),
                            'code' => $category['code'],
                        ],
                        [
                            'name' => $category['name'],
                            'group_type' => $category['group_type'],
                            'education_level' => $category['education_level'] ?? null,
                            'grade_start' => $category['grade_start'] ?? null,
                            'grade_end' => $category['grade_end'] ?? null,
                            'portion_size' => $category['portion_size'],
                            'menu_audience' => $category['menu_audience'],
                            'sort_order' => $category['sort_order'],
                            'is_active' => true,
                        ],
                    );
                }
            });
    }
}
