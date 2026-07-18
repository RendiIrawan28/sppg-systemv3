<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MbgV14PortionStandardSeeder extends Seeder
{
    /**
     * Nilai ini merupakan tabel Q:T pada sheet "Standar Gizi siswa".
     * Format: nama bahan => [jumlah kecil, jumlah besar, komponen, satuan resep, baris sumber].
     */
    private const STANDARDS = [
        'BERAS' => [40, 80, 'staple', 'g', 6], 'NASI' => [100, 150, 'staple', 'g', 7],
        'KENTANG' => [100, 100, 'staple', 'g', 8], 'MIE' => [40, 80, 'staple', 'g', 9],
        'SPAGHETTI' => [40, 80, 'staple', 'g', 10], 'ROTI TAWAR' => [2, 2, 'staple', 'pcs', 11],
        'ROTI BURGER' => [1, 1, 'staple', 'pcs', 12], 'TORTILA KECIL' => [1, 1, 'staple', 'pcs', 13],
        'TORTILA BESAR' => [1, 1, 'staple', 'pcs', 14], 'BIHUN' => [40, 80, 'staple', 'g', 15],
        'ROTI BURGER SARI ROTI' => [1, 1, 'staple', 'pcs', 16], 'ROTI HOTDOG UMKM' => [1, 1, 'staple', 'pcs', 17],
        'ROTI ABON' => [1, 1, 'staple', 'pcs', 18], 'ROTI KEJU' => [1, 1, 'staple', 'pcs', 19],
        'ROTI SKUY' => [1, 1, 'staple', 'pcs', 20], 'ROTI PISANG COKLAT' => [1, 1, 'staple', 'pcs', 21],

        'AYAM' => [40, 50, 'animal_protein', 'g', 28], 'ABON SAPI' => [30, 30, 'animal_protein', 'g', 29],
        'AYAM FILLET' => [45, 45, 'animal_protein', 'g', 30], 'BAKSO SAPI' => [48, 60, 'animal_protein', 'g', 31],
        'BEEF BURGER' => [1, 1, 'animal_protein', 'pcs', 32], 'CUMI-CUMI' => [30, 30, 'animal_protein', 'g', 33],
        'DAGING GILING AYAM' => [40, 40, 'animal_protein', 'g', 34], 'DAGING SAPI' => [33.3, 33.3, 'animal_protein', 'g', 35],
        'DAGING SAPI GILING' => [40, 40, 'animal_protein', 'g', 36], 'IKAN DORI FILLET' => [45, 45, 'animal_protein', 'g', 37],
        'IKAN KAKAP FILLET' => [45, 45, 'animal_protein', 'g', 38], 'IKAN KEMBUNG' => [100, 100, 'animal_protein', 'g', 39],
        'IKAN LELE' => [65, 65, 'animal_protein', 'g', 40], 'KAKAP MERAH' => [60, 60, 'animal_protein', 'g', 41],
        'KAKAP PUTIH' => [45, 45, 'animal_protein', 'g', 42], 'TELUR AYAM' => [56, 56, 'animal_protein', 'g', 43],
        'TELUR BEBEK' => [1, 1, 'animal_protein', 'pcs', 44], 'UDANG' => [27, 27, 'animal_protein', 'g', 45],
        'AYAM, DADA' => [50, 50, 'animal_protein', 'g', 46], 'AYAM, PAHA' => [50, 50, 'animal_protein', 'g', 47],
        'AYAM, SAYAP' => [50, 50, 'animal_protein', 'g', 48], 'TELUR ASIN (BEBEK)' => [1, 1, 'animal_protein', 'pcs', 50],
        'TELUR PUYUH' => [45, 45, 'animal_protein', 'g', 51], 'SEREAL COKLAT' => [20, 20, 'animal_protein', 'g', 52],

        'TAHU' => [1, 1, 'plant_protein', 'pcs', 55], 'EDAMAME' => [25, 25, 'plant_protein', 'g', 56],
        'KACANG HIJAU' => [30, 30, 'plant_protein', 'g', 58], 'KACANG MERAH' => [12.5, 25, 'plant_protein', 'g', 59],
        'KACANG POLONG' => [21, 21, 'plant_protein', 'g', 60], 'KERIPIK TEMPE' => [15, 15, 'plant_protein', 'g', 61],
        'PANGSIT' => [12, 12, 'plant_protein', 'g', 62], 'TAHU COKLAT' => [1, 1, 'plant_protein', 'pcs', 63],
        'TAHU KUNING' => [1, 1, 'plant_protein', 'pcs', 64], 'TEMPE' => [32, 32, 'plant_protein', 'g', 65],
        'KACANG KORO UMKM' => [1, 1, 'plant_protein', 'pcs', 66], 'KEJU SLICE (PROCHIZE)' => [1, 1, 'plant_protein', 'pcs', 67],
        'KERIPIK TEMPE KEMAS' => [1, 1, 'plant_protein', 'pcs', 68], 'KACANG TANAH' => [12, 12, 'plant_protein', 'g', 69],
        'GARAM UNTUK SEMUA' => [0.6, 0.9, 'seasoning', 'g', 81],

        'PEAR' => [350, 350, 'fruit', 'g', 91], 'APEL MALANG' => [120, 120, 'fruit', 'g', 92],
        'APEL' => [1, 1, 'fruit', 'pcs', 93], 'ANGGUR MERAH' => [48, 65, 'fruit', 'g', 94],
        'ANGGUR MUSCAT' => [40, 40, 'fruit', 'g', 95], 'BUAH NAGA MERAH' => [80, 80, 'fruit', 'g', 96],
        'BUAH NAGA PUTIH' => [80, 80, 'fruit', 'g', 97], 'JERUK' => [80, 80, 'fruit', 'g', 98],
        'KELENGKENG' => [45, 45, 'fruit', 'g', 99], 'MANGGIS' => [40, 40, 'fruit', 'g', 100],
        'MELON' => [90, 90, 'fruit', 'g', 101], 'NANAS' => [85, 85, 'fruit', 'g', 102],
        'PEPAYA' => [70, 70, 'fruit', 'g', 103], 'PISANG' => [100, 100, 'fruit', 'g', 104],
        'PISANG AMBON' => [100, 100, 'fruit', 'g', 105], 'PISANG CAVENDIS' => [100, 100, 'fruit', 'g', 106],
        'PISANG KEPOK' => [100, 100, 'fruit', 'g', 107], 'PISANG LAMPUNG' => [30, 60, 'fruit', 'g', 108],
        'PISANG MAS' => [100, 100, 'fruit', 'g', 109], 'PISANG RAJA' => [100, 100, 'fruit', 'g', 110],
        'PISANG ULI' => [50, 75, 'fruit', 'g', 111], 'SALAK' => [60, 60, 'fruit', 'g', 112],
        'STRAWBARRY' => [40, 40, 'fruit', 'g', 113], 'SEMANGKA' => [110, 110, 'fruit', 'g', 114],
        'KURMA' => [35, 45, 'fruit', 'g', 115], 'JERUK SANTANG' => [60, 60, 'fruit', 'g', 116],
        'KURMA KEMAS' => [1, 1, 'fruit', 'pcs', 117], 'JERUK SANKIST' => [60, 60, 'fruit', 'g', 118],
        'JAMBU BIJI MERAH' => [75, 75, 'fruit', 'g', 119], 'BOLA BOLA UBI' => [1, 1, 'fruit', 'pcs', 120],
        'PRENAGEN' => [1, 1, 'milk', 'pcs', 341], 'BEAR BRAND' => [1, 1, 'milk', 'pcs', 342],
        'BISKUAT BISKUIT COKLAT' => [1, 1, 'staple', 'pcs', 343], 'BISKUAT BOLU COKLAT' => [1, 1, 'staple', 'pcs', 344],
        'BISKUAT BOLU PANDAN' => [1, 1, 'staple', 'pcs', 345], 'BISKUAT ORIGINAL' => [1, 1, 'staple', 'pcs', 346],
        'BISKUAT SANDWICH COKLAT' => [1, 1, 'staple', 'pcs', 347],
    ];

    public function run(): void
    {
        if (! Schema::hasTable('ingredient_portion_standards')) {
            return;
        }

        DB::table('sppg_units')->where('is_active', true)->pluck('id')->each(
            fn ($unitId) => $this->runForUnit((int) $unitId)
        );
    }

    public function runForUnit(int $unitId): void
    {
        $units = DB::table('measurement_units')->whereIn('code', ['g', 'gram', 'pcs', 'piece', 'buah'])->get()->keyBy('code');
        $gram = $units->get('g') ?? $units->get('gram');
        $piece = $units->get('pcs') ?? $units->get('piece') ?? $units->get('buah');
        $now = now();

        foreach (self::STANDARDS as $name => [$small, $large, $component, $unitCode, $sourceRow]) {
            $ingredient = DB::table('ingredients')->where('sppg_unit_id', $unitId)->whereRaw('UPPER(name) = ?', [$name])->first();
            $unit = $unitCode === 'pcs' ? $piece : $gram;

            if (! $ingredient || ! $unit) {
                continue;
            }

            $gramsPerUnit = $unitCode === 'pcs' ? (float) ($ingredient->grams_per_unit ?: 1) : 1.0;
            DB::table('ingredient_portion_standards')->updateOrInsert(
                ['sppg_unit_id' => $unitId, 'ingredient_id' => $ingredient->id],
                ['measurement_unit_id' => $unit->id, 'component_type' => $component,
                    'small_quantity' => $small, 'large_quantity' => $large,
                    'toddler_quantity' => $small, 'maternal_quantity' => $large,
                    'grams_per_unit' => $gramsPerUnit, 'source' => 'Standar Gizi siswa',
                    'source_row' => $sourceRow, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
            );

            $standardId = DB::table('ingredient_portion_standards')
                ->where('sppg_unit_id', $unitId)->where('ingredient_id', $ingredient->id)->value('id');

            // Resep lama yang belum pernah ditandai sebagai penyesuaian ikut
            // diselaraskan, sama seperti VLOOKUP saat workbook dibuka ulang.
            DB::table('recipe_ingredients')
                ->where('ingredient_id', $ingredient->id)
                ->where('portion_override', false)
                ->update([
                    'ingredient_portion_standard_id' => $standardId,
                    'portion_source' => 'standard',
                    'measurement_unit_id' => $unit->id,
                    'grams_per_unit_snapshot' => $gramsPerUnit,
                    'input_quantity_small' => $small,
                    'input_quantity_large' => $large,
                    'input_quantity_toddler' => $small,
                    'input_quantity_maternal' => $large,
                    'quantity_small_grams' => $small * $gramsPerUnit,
                    'quantity_large_grams' => $large * $gramsPerUnit,
                    'quantity_toddler_grams' => $small * $gramsPerUnit,
                    'quantity_maternal_grams' => $large * $gramsPerUnit,
                    'quantity' => $small,
                    'quantity_grams' => $small * $gramsPerUnit,
                    'updated_at' => $now,
                ]);
        }

        DB::table('recipe_ingredients as ri')
            ->join('menu_items as mi', 'mi.id', '=', 'ri.menu_item_id')
            ->join('menus as m', 'm.id', '=', 'mi.menu_id')
            ->where('m.sppg_unit_id', $unitId)
            ->select(['ri.menu_item_id', 'ri.quantity_small_grams', 'ri.quantity_large_grams', 'ri.quantity_toddler_grams', 'ri.quantity_maternal_grams'])
            ->get()->groupBy('menu_item_id')->each(function ($rows, $menuItemId) use ($now): void {
                DB::table('menu_items')->where('id', $menuItemId)->update([
                    'portion_weight_small_grams' => $rows->sum('quantity_small_grams'),
                    'portion_weight_large_grams' => $rows->sum('quantity_large_grams'),
                    'portion_weight_toddler_grams' => $rows->sum('quantity_toddler_grams'),
                    'portion_weight_maternal_grams' => $rows->sum('quantity_maternal_grams'),
                    'updated_at' => $now,
                ]);
            });
    }
}
