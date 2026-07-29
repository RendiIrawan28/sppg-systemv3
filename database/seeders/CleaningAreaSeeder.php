<?php

namespace Database\Seeders;

use App\Models\CleaningArea;
use App\Models\SppgUnit;
use App\Support\CleaningChecklistTemplate;
use Illuminate\Database\Seeder;

class CleaningAreaSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SppgUnit::query()->get() as $unit) {
            $areas = [
                [
                    'code' => 'TOILET-1', 'name' => 'Toilet Utama', 'category' => 'toilet',
                    'template_type' => CleaningChecklistTemplate::TOILET,
                    'location' => 'Area toilet', 'frequency' => 'daily', 'scheduled_time' => '07:00:00',
                ],
                [
                    'code' => 'PRODUKSI', 'name' => 'Area Produksi', 'category' => 'production',
                    'template_type' => CleaningChecklistTemplate::PRODUCTION,
                    'location' => 'Area produksi', 'frequency' => 'daily', 'scheduled_time' => '10:00:00',
                ],
                [
                    'code' => 'PEMORSIAN', 'name' => 'Area Pemorsian', 'category' => 'portioning',
                    'template_type' => CleaningChecklistTemplate::PORTIONING,
                    'location' => 'Area pemorsian', 'frequency' => 'daily', 'scheduled_time' => '07:00:00',
                ],
                [
                    'code' => 'GUDANG-BASAH', 'name' => 'Gudang Basah', 'category' => 'warehouse',
                    'template_type' => CleaningChecklistTemplate::WAREHOUSE,
                    'location' => 'Gudang basah', 'frequency' => 'daily', 'scheduled_time' => '08:00:00',
                ],
                [
                    'code' => 'GUDANG-KERING', 'name' => 'Gudang Kering', 'category' => 'warehouse',
                    'template_type' => CleaningChecklistTemplate::WAREHOUSE,
                    'location' => 'Gudang kering', 'frequency' => 'daily', 'scheduled_time' => '08:00:00',
                ],
                [
                    'code' => 'GUDANG-DINGIN', 'name' => 'Gudang Dingin', 'category' => 'warehouse',
                    'template_type' => CleaningChecklistTemplate::WAREHOUSE,
                    'location' => 'Gudang dingin', 'frequency' => 'daily', 'scheduled_time' => '08:00:00',
                ],
                [
                    'code' => 'CUCI', 'name' => 'Area Pencucian Ompreng', 'category' => 'washing',
                    'template_type' => CleaningChecklistTemplate::CUSTOM,
                    'location' => 'Area pencucian', 'frequency' => 'daily', 'scheduled_time' => null,
                    'auto_schedule' => false,
                    'default_checklist' => [
                        ['category' => 'washing', 'item_name' => 'Lantai bersih dan tidak licin', 'is_mandatory' => true],
                        ['category' => 'washing', 'item_name' => 'Tidak ada sisa makanan dan genangan air', 'is_mandatory' => true],
                        ['category' => 'washing', 'item_name' => 'Meja, bak, dan peralatan pencucian bersih', 'is_mandatory' => true],
                        ['category' => 'washing', 'item_name' => 'Saluran air tidak tersumbat', 'is_mandatory' => true],
                    ],
                ],
            ];

            foreach ($areas as $area) {
                $defaultChecklist = $area['default_checklist']
                    ?? CleaningChecklistTemplate::items($area['template_type']);

                CleaningArea::query()->updateOrCreate(
                    ['sppg_unit_id' => $unit->getKey(), 'code' => $area['code']],
                    [
                        'name' => $area['name'],
                        'category' => $area['category'],
                        'template_type' => $area['template_type'],
                        'location' => $area['location'],
                        'frequency' => $area['frequency'],
                        'auto_schedule' => $area['auto_schedule'] ?? true,
                        'scheduled_time' => $area['scheduled_time'],
                        'standard_duration_minutes' => 60,
                        'instructions' => 'Isi checklist sesuai kondisi aktual. Item yang tidak terpenuhi wajib diberi evaluasi.',
                        'default_checklist' => $defaultChecklist,
                        'is_active' => true,
                    ],
                );
            }

            CleaningArea::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->whereIn('code', ['DAPUR', 'GUDANG', 'KEND'])
                ->update(['is_active' => false, 'auto_schedule' => false]);
        }
    }
}
