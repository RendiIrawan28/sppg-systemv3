<?php

namespace Database\Seeders;

use App\Models\CleaningArea;
use App\Models\SppgUnit;
use Illuminate\Database\Seeder;

class CleaningAreaSeeder extends Seeder
{
    public function run(): void
    {
        $units = SppgUnit::query()->get();

        foreach ($units as $unit) {
            $areas = [
                ['code' => 'CUCI', 'name' => 'Area Pencucian Ompreng', 'category' => 'washing', 'location' => 'Area pencucian', 'frequency' => 'daily'],
                ['code' => 'DAPUR', 'name' => 'Area Produksi/Dapur', 'category' => 'production', 'location' => 'Area produksi', 'frequency' => 'daily'],
                ['code' => 'GUDANG', 'name' => 'Area Gudang Bahan', 'category' => 'storage', 'location' => 'Gudang', 'frequency' => 'daily'],
                ['code' => 'KEND', 'name' => 'Area Parkir/Kendaraan Distribusi', 'category' => 'vehicle', 'location' => 'Area luar', 'frequency' => 'daily'],
            ];

            foreach ($areas as $area) {
                CleaningArea::query()->updateOrCreate(
                    [
                        'sppg_unit_id' => $unit->getKey(),
                        'code' => $area['code'],
                    ],
                    [
                        'name' => $area['name'],
                        'category' => $area['category'],
                        'location' => $area['location'],
                        'frequency' => $area['frequency'],
                        'standard_duration_minutes' => 60,
                        'instructions' => 'Ikuti SOP kebersihan area, dokumentasikan kondisi sebelum dan sesudah, serta catat temuan bila ada.',
                        'default_checklist' => [
                            'Area bebas sisa makanan dan sampah',
                            'Lantai/meja/peralatan bersih',
                            'Tidak ada genangan air',
                            'Tidak ada tanda hama atau kontaminasi',
                        ],
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
