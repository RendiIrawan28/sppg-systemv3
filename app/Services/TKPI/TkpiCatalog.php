<?php

namespace App\Services\TKPI;

final class TkpiCatalog
{
    /**
     * Kode komponen dibuat seragam dengan modul gizi internal.
     * Alias lama TKPI tetap disimpan agar importer bisa membaca CSV TKPI.
     *
     * @return array<string, array{name:string, unit:string, sort_order:int, aliases:array<int,string>}>
     */
    public static function components(): array
    {
        return [
            'energy' => ['name' => 'Energi', 'unit' => 'kcal', 'sort_order' => 1, 'aliases' => ['ENERC', 'ENERGY', 'ENERGI']],
            'protein' => ['name' => 'Protein', 'unit' => 'g', 'sort_order' => 2, 'aliases' => ['PROCNT', 'PROTEIN']],
            'fat' => ['name' => 'Lemak', 'unit' => 'g', 'sort_order' => 3, 'aliases' => ['FAT', 'LEMAK']],
            'carbohydrate' => ['name' => 'Karbohidrat', 'unit' => 'g', 'sort_order' => 4, 'aliases' => ['CHOCDF', 'CARBOHYDRATE', 'KARBOHIDRAT']],
            'fiber' => ['name' => 'Serat', 'unit' => 'g', 'sort_order' => 5, 'aliases' => ['FIBC', 'FIBTG', 'FIBER', 'SERAT']],
            'sodium' => ['name' => 'Natrium', 'unit' => 'mg', 'sort_order' => 6, 'aliases' => ['NA', 'SODIUM', 'NATRIUM']],
            'iron' => ['name' => 'Zat Besi', 'unit' => 'mg', 'sort_order' => 7, 'aliases' => ['FE', 'IRON', 'BESI', 'ZAT BESI']],
            'vitamin_a' => ['name' => 'Vitamin A', 'unit' => 'mcg', 'sort_order' => 8, 'aliases' => ['VITA', 'VITAMIN A']],
            'vitamin_c' => ['name' => 'Vitamin C', 'unit' => 'mg', 'sort_order' => 9, 'aliases' => ['VITC', 'VIT C', 'VITAMIN C']],
            'vitamin_e' => ['name' => 'Vitamin E', 'unit' => 'mg', 'sort_order' => 10, 'aliases' => ['VITE', 'VITAMIN E']],
            'vitamin_d' => ['name' => 'Vitamin D', 'unit' => 'mcg', 'sort_order' => 11, 'aliases' => ['VITD', 'VITAMIN D']],
            'vitamin_k' => ['name' => 'Vitamin K', 'unit' => 'mcg', 'sort_order' => 12, 'aliases' => ['VITK', 'VITAMIN K']],
            'vitamin_b12' => ['name' => 'Vitamin B12', 'unit' => 'mcg', 'sort_order' => 13, 'aliases' => ['VITB12', 'VITAMIN B12']],

            // Komponen tambahan dari TKPI. Tidak dipakai sebagai validasi utama MBG,
            // tetapi tetap disimpan untuk nilai gizi bahan pangan per 100 gram.
            'water' => ['name' => 'Air', 'unit' => 'g', 'sort_order' => 101, 'aliases' => ['WATER', 'AIR']],
            'ash' => ['name' => 'Abu', 'unit' => 'g', 'sort_order' => 102, 'aliases' => ['ASH', 'ABU']],
            'calcium' => ['name' => 'Kalsium', 'unit' => 'mg', 'sort_order' => 103, 'aliases' => ['CA', 'CALCIUM', 'KALSIUM']],
            'phosphorus' => ['name' => 'Fosfor', 'unit' => 'mg', 'sort_order' => 104, 'aliases' => ['P', 'PHOSPHORUS', 'FOSFOR']],
            'potassium' => ['name' => 'Kalium', 'unit' => 'mg', 'sort_order' => 105, 'aliases' => ['K', 'POTASSIUM', 'KALIUM']],
            'copper' => ['name' => 'Tembaga', 'unit' => 'mg', 'sort_order' => 106, 'aliases' => ['CU', 'COPPER', 'TEMBAGA']],
            'zinc' => ['name' => 'Seng', 'unit' => 'mg', 'sort_order' => 107, 'aliases' => ['ZN', 'ZINC', 'SENG']],
            'retinol' => ['name' => 'Retinol', 'unit' => 'mcg', 'sort_order' => 108, 'aliases' => ['RETOL', 'RETINOL']],
            'beta_carotene' => ['name' => 'Beta-karoten', 'unit' => 'mcg', 'sort_order' => 109, 'aliases' => ['CARTB', 'BETA_CAROTENE', 'BETA-KAROTEN']],
            'total_carotene' => ['name' => 'Karoten Total', 'unit' => 'mcg', 'sort_order' => 110, 'aliases' => ['CARTOL', 'TOTAL_CAROTENE', 'KAROTEN TOTAL']],
            'thiamin' => ['name' => 'Thiamin (Vitamin B1)', 'unit' => 'mg', 'sort_order' => 111, 'aliases' => ['THIA', 'THIAMIN', 'VITAMIN B1']],
            'riboflavin' => ['name' => 'Riboflavin (Vitamin B2)', 'unit' => 'mg', 'sort_order' => 112, 'aliases' => ['RIBF', 'RIBOFLAVIN', 'VITAMIN B2']],
            'niacin' => ['name' => 'Niasin (Vitamin B3)', 'unit' => 'mg', 'sort_order' => 113, 'aliases' => ['NIA', 'NIACIN', 'NIASIN', 'VITAMIN B3']],
        ];
    }

    /** @return array<string,string> */
    public static function csvComponentMap(): array
    {
        return [
            'water_g' => 'water',
            'energy_kcal' => 'energy',
            'protein_g' => 'protein',
            'fat_g' => 'fat',
            'carbohydrate_g' => 'carbohydrate',
            'fiber_g' => 'fiber',
            'ash_g' => 'ash',
            'calcium_mg' => 'calcium',
            'phosphorus_mg' => 'phosphorus',
            'iron_mg' => 'iron',
            'sodium_mg' => 'sodium',
            'potassium_mg' => 'potassium',
            'copper_mg' => 'copper',
            'zinc_mg' => 'zinc',
            'retinol_mcg' => 'retinol',
            'beta_carotene_mcg' => 'beta_carotene',
            'total_carotene_mcg' => 'total_carotene',
            'thiamin_mg' => 'thiamin',
            'riboflavin_mg' => 'riboflavin',
            'niacin_mg' => 'niacin',
            'vitamin_c_mg' => 'vitamin_c',
        ];
    }
}
