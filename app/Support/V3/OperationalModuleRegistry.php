<?php

namespace App\Support\V3;

use App\Enums\CleaningFindingSeverity;
use App\Enums\CleaningFindingStatus;
use App\Enums\DistributionStopStatus;
use App\Enums\WashingDeviationSeverity;
use App\Enums\WashingDeviationStatus;
use App\Models\CleaningArea;
use App\Models\CleaningSession;
use App\Models\ContainerCollectionRun;
use App\Models\DistributionRun;
use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\PortioningSession;
use App\Models\ProcessingBatch;
use App\Models\User;
use App\Models\WashingSession;

final class OperationalModuleRegistry
{
    /** @return array<int, string> */
    public function slugs(): array
    {
        return array_keys($this->definitions());
    }

    /** @return array<int, string> */
    public function genericWebSlugs(): array
    {
        return ['distribusi', 'pencucian', 'kebersihan'];
    }

    /** @return array<string, mixed> */
    public function get(string $slug): array
    {
        abort_unless(isset($this->definitions()[$slug]), 404);

        return $this->definitions()[$slug];
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            'pengolahan' => [
                'label' => 'Pengolahan', 'description' => 'Bahan dari Gudang, hasil produksi, suhu makanan matang, dan dokumentasi.',
                'model' => ProcessingBatch::class, 'permission' => 'processing', 'number' => 'batch_number',
                'date' => 'production_date', 'pdf' => 'processing-batches.pdf',
                'fields' => [
                    $this->field('production_date', 'Tanggal produksi', 'date', true),
                    $this->field('menu_name_snapshot', 'Menu', 'text', true),
                    $this->field('product_name', 'Nama hasil produksi', 'text', true),
                    $this->field('target_output_quantity', 'Target hasil', 'number', true),
                    $this->field('target_output_unit', 'Satuan target', 'text', true),
                    $this->field('actual_output_quantity', 'Realisasi hasil', 'number'),
                    $this->field('actual_output_unit', 'Satuan realisasi'),
                    $this->field('petugas_id', 'Penanggung jawab', 'select', true, 'users'),
                    $this->field('notes', 'Catatan', 'textarea'),
                ],
                'relations' => [
                    'materialUsages' => $this->relation('Bahan baku digunakan', [
                        $this->field('ingredient_id', 'Bahan', 'select', false, 'ingredients'), $this->field('material_name', 'Nama bahan', 'text', true),
                        $this->field('quantity', 'Jumlah', 'number', true), $this->field('measurement_unit_id', 'Satuan master', 'select', false, 'measurement_units'),
                        $this->field('unit_name', 'Nama satuan', 'text', true), $this->field('sort_order', 'Urutan', 'number'), $this->field('notes', 'Catatan', 'textarea'),
                    ]),
                    'temperatureLogs' => $this->relation('Pemantauan suhu', [
                        $this->field('checked_at', 'Waktu makanan matang', 'datetime', true),
                        $this->field('product_name', 'Produk', 'text', true), $this->field('temperature_celsius', 'Suhu aktual °C', 'number', true),
                        $this->field('notes', 'Catatan', 'textarea'),
                    ]),
                    'documentations' => $this->relation('Dokumentasi', [
                        $this->field('photo_path', 'Foto makanan matang', 'file', true), $this->field('caption', 'Nama makanan', 'text', true),
                        $this->field('captured_at', 'Waktu foto', 'datetime'), $this->field('sort_order', 'Urutan', 'number'),
                    ]),
                ],
            ],
            'pemorsian' => [
                'label' => 'Pemorsian', 'description' => 'Jumlah porsi otomatis dari rencana, dokumentasi hasil, dan pencatatan sisa makanan.',
                'model' => PortioningSession::class, 'permission' => 'portioning', 'number' => 'session_number',
                'date' => 'portioning_date', 'pdf' => 'portioning-sessions.pdf',
                'fields' => [
                    $this->field('portioning_date', 'Tanggal pemorsian', 'date', true),
                    $this->field('menu_name_snapshot', 'Menu', 'text', true),
                    $this->field('target_small_portions', 'Target kecil', 'number'), $this->field('target_large_portions', 'Target besar', 'number'),
                    $this->field('notes', 'Catatan', 'textarea'),
                ],
                'relations' => [
                    'routeAllocations' => $this->relation('Pembagian porsi per rute', [
                        $this->field('route_name', 'Rute', 'text', true),
                        $this->field('destination_name', 'Tujuan', 'text', true),
                        $this->field('target_small_portions', 'Target kecil', 'number'), $this->field('target_large_portions', 'Target besar', 'number'),
                    ]),
                    'routeRecords' => $this->relation('Hasil Pemorsian per rute', [
                        $this->field('route_name', 'Nama rute', 'text', true),
                        $this->field('small_portions', 'Porsi kecil', 'number', true),
                        $this->field('large_portions', 'Porsi besar', 'number', true),
                        $this->field('completed_at', 'Waktu selesai', 'datetime', true),
                        $this->field('photo_path', 'Dokumentasi rute', 'file', true),
                        $this->field('notes', 'Catatan', 'textarea'),
                    ]),
                    'leftoverRecords' => $this->relation('Sisa makanan setelah Pemorsian', [
                        $this->field('food_type', 'Menu makanan', 'text', true),
                        $this->field('quantity', 'Jumlah', 'number', true),
                        $this->field('unit_name', 'Satuan', 'text', true),
                        $this->field('photo_path', 'Dokumentasi sisa', 'file', true),
                        $this->field('notes', 'Keterangan', 'textarea'),
                    ]),
                    'supplies' => $this->relation('Barang yang diambil dari Gudang', [
                        $this->field('supply_name', 'Barang', 'text', true),
                        $this->field('quantity', 'Jumlah', 'number', true),
                        $this->field('unit_name', 'Satuan', 'text', true),
                        $this->field('source_reference', 'Referensi pengambilan'),
                        $this->field('notes', 'Catatan', 'textarea'),
                    ]),
                ],
            ],
            'distribusi' => [
                'label' => 'Distribusi',
                'description' => 'Driver memilih rute, memuat, mengantar setiap tujuan, kembali ke SPPG, lalu laporan seluruh rute diajukan bersama.',
                'model' => DistributionRun::class,
                'permission' => 'distribution',
                'number' => 'run_number',
                'date' => 'distribution_date',
                'pdf' => 'distribution-runs.pdf',
                'fields' => [
                    $this->field('distribution_date', 'Tanggal distribusi', 'date', true),
                    $this->field('route_name', 'Nama rute', 'text', true),
                    $this->field('menu_name_snapshot', 'Menu', 'text', true),
                    $this->field('driver_name', 'Nama driver'),
                    $this->field('kernet_name', 'Nama kernet'),
                    $this->field('vehicle_name', 'Jenis/nama kendaraan'),
                    $this->field('vehicle_plate', 'Nomor polisi'),
                    $this->field('departure_temperature_celsius', 'Suhu saat berangkat °C', 'number'),
                ],
                'relations' => [
                    'stops' => $this->relation('Tujuan pada rute', [
                        $this->field('route_name', 'Rute', 'text', true),
                        $this->field('destination_name', 'Tujuan', 'text', true),
                        $this->field('sequence_order', 'Urutan pengantaran', 'number', true),
                        $this->field('planned_arrival_at', 'Jadwal tiba', 'datetime'),
                        $this->field('address', 'Alamat', 'textarea'),
                        $this->field('contact_name', 'Kontak tujuan'),
                        $this->field('contact_phone', 'Nomor telepon'),
                        $this->field('small_portions', 'Rencana porsi kecil', 'number'),
                        $this->field('large_portions', 'Rencana porsi besar', 'number'),
                        $this->field('delivered_small_portions', 'Porsi kecil diserahkan', 'number'),
                        $this->field('delivered_large_portions', 'Porsi besar diserahkan', 'number'),
                        $this->field('containers_sent', 'Ompreng/wadah diserahkan', 'number'),
                        $this->field('recipient_name', 'Nama penerima'),
                        $this->field('recipient_position', 'Jabatan penerima'),
                        $this->field('handover_photo_path', 'Foto serah-terima', 'file'),
                        $this->field('failure_reason', 'Alasan pengiriman sebagian/gagal', 'textarea'),
                        $this->field('status', 'Status tujuan', 'select', true, DistributionStopStatus::class),
                        $this->field('notes', 'Catatan tujuan', 'textarea'),
                    ]),
                ],
            ],
            'pencucian' => [
                'label' => 'Pencucian',
                'description' => 'Penerimaan ompreng, pencatatan limbah makanan, pencucian, dan dokumentasi hasil.',
                'model' => WashingSession::class,
                'permission' => 'washing',
                'number' => 'session_number',
                'date' => 'washing_date',
                'pdf' => 'washing-sessions.pdf',
                'fields' => [
                    $this->field('washing_date', 'Tanggal pencucian', 'date', true),
                    $this->field('container_collection_run_id', 'Kegiatan pengambilan ompreng', 'select', false, 'container_collection_runs'),
                    $this->field('menu_name_snapshot', 'Referensi pengambilan'),
                    $this->field('distribution_expected_containers', 'Total hasil pengambilan', 'number'),
                    $this->field('distribution_returned_containers', 'Dilaporkan dibawa kembali', 'number'),
                    $this->field('expected_containers', 'Seharusnya diserahkan ke Pencucian', 'number', true),
                    $this->field('received_containers', 'Diterima fisik', 'number', true),
                    $this->field('clean_containers', 'Bersih dan siap digunakan', 'number', true),
                    $this->field('damaged_containers', 'Rusak/tidak layak', 'number', true),
                    $this->field('missing_containers', 'Kurang saat serah-terima', 'number'),
                    $this->field('receiving_difference', 'Selisih penerimaan', 'number'),
                    $this->field('has_food_waste', 'Terdapat limbah makanan', 'boolean'),
                    $this->field('no_waste_confirmed', 'Konfirmasi tidak ada limbah', 'boolean'),
                    $this->field('waste_first_party_name', 'Nama pihak pertama'),
                    $this->field('waste_first_party_position', 'Jabatan pihak pertama'),
                    $this->field('waste_first_party_address', 'Alamat pihak pertama', 'textarea'),
                    $this->field('waste_second_party_name', 'Nama pihak kedua'),
                    $this->field('waste_second_party_position', 'Jabatan pihak kedua'),
                    $this->field('waste_second_party_address', 'Alamat pihak kedua', 'textarea'),
                    $this->field('waste_handover_notes', 'Catatan serah-terima limbah', 'textarea'),
                    $this->field('notes', 'Catatan pencucian', 'textarea'),
                ],
                'relations' => [
                    'checklistItems' => $this->relation('Checklist pencucian', [
                        $this->field('category', 'Tahap', 'text', true),
                        $this->field('item_name', 'Poin pemeriksaan', 'text', true),
                        $this->field('is_mandatory', 'Wajib', 'boolean'),
                        $this->field('is_passed', 'Selesai', 'boolean'),
                        $this->field('notes', 'Catatan', 'textarea'),
                        $this->field('sort_order', 'Urutan', 'number'),
                    ]),
                    'wasteRecords' => $this->relation('Limbah makanan dari ompreng', [
                        $this->field('waste_type', 'Jenis limbah', 'text', true),
                        $this->field('quantity', 'Jumlah', 'number', true),
                        $this->field('unit', 'Satuan', 'select', true, [
                            'kg' => 'kg',
                            'gram' => 'gram',
                            'liter' => 'liter',
                            'wadah' => 'wadah',
                            'karung' => 'karung',
                        ]),
                        $this->field('disposal_method', 'Metode penanganan', 'select', true, [
                            'diserahkan' => 'Diserahkan ke pengelola limbah',
                            'kompos' => 'Diolah menjadi kompos',
                            'pakan' => 'Diserahkan untuk pakan',
                            'dibuang' => 'Dibuang sesuai prosedur',
                            'lainnya' => 'Metode lainnya',
                        ]),
                        $this->field('handed_over_to', 'Diserahkan kepada', 'text', true),
                        $this->field('photo_path', 'Foto limbah', 'file', true),
                        $this->field('notes', 'Catatan', 'textarea'),
                    ]),
                    'documentations' => $this->relation('Dokumentasi hasil pencucian', [
                        $this->field('phase', 'Tahap', 'select', true, ['after' => 'Hasil pencucian']),
                        $this->field('photo_path', 'Foto hasil', 'file', true),
                        $this->field('caption', 'Keterangan'),
                        $this->field('captured_at', 'Waktu foto', 'datetime'),
                        $this->field('sort_order', 'Urutan', 'number'),
                    ]),
                ],
            ],
            'kebersihan' => [
                'label' => 'Kebersihan', 'description' => 'Jadwal area, checklist, bahan pembersih, temuan, limbah, dan dokumentasi.',
                'model' => CleaningSession::class, 'permission' => 'cleaning', 'number' => 'session_number',
                'date' => 'scheduled_date', 'pdf' => 'cleaning-sessions.pdf',
                'fields' => [
                    $this->field('scheduled_date', 'Tanggal jadwal', 'date', true), $this->field('shift', 'Shift', 'select', true, ['morning' => 'Pagi', 'afternoon' => 'Siang', 'night' => 'Malam']),
                    $this->field('scheduled_start_at', 'Rencana mulai', 'datetime'), $this->field('cleaning_area_id', 'Area', 'select', true, 'cleaning_areas'),
                    $this->field('petugas_id', 'Petugas', 'select', true, 'users'), $this->field('supervisor_id', 'Supervisor', 'select', false, 'users'),
                    $this->field('before_condition', 'Kondisi sebelum', 'textarea'), $this->field('after_condition', 'Kondisi setelah', 'textarea'),
                    $this->field('notes', 'Catatan', 'textarea'),
                ],
                'relations' => [
                    'checklistItems' => $this->relation('Checklist area', [
                        $this->field('category', 'Kategori', 'select', true, ['preparation' => 'Persiapan', 'surface' => 'Permukaan', 'floor' => 'Lantai', 'drain' => 'Saluran', 'equipment' => 'Peralatan', 'final' => 'Akhir', 'other' => 'Lainnya']),
                        $this->field('item_name', 'Poin pemeriksaan', 'text', true), $this->field('is_mandatory', 'Wajib', 'boolean'),
                        $this->field('result', 'Hasil', 'select', true, ['pending' => 'Belum diperiksa', 'pass' => 'Lulus', 'fail' => 'Tidak lulus', 'not_applicable' => 'Tidak berlaku']),
                        $this->field('checked_at', 'Waktu cek', 'datetime'), $this->field('checked_by', 'Diperiksa oleh', 'select', false, 'users'),
                        $this->field('notes', 'Catatan', 'textarea'), $this->field('sort_order', 'Urutan', 'number'),
                    ]),
                    'chemicalUsages' => $this->chemicalRelation(true), 'documentations' => $this->documentationRelation(),
                    'findings' => $this->relation('Temuan dan koreksi', [
                        $this->field('found_at', 'Waktu temuan', 'datetime', true), $this->field('category', 'Kategori', 'text', true),
                        $this->field('severity', 'Tingkat', 'select', true, CleaningFindingSeverity::class), $this->field('status', 'Status', 'select', true, CleaningFindingStatus::class),
                        $this->field('description', 'Deskripsi', 'textarea', true), $this->field('corrective_action', 'Tindakan koreksi', 'textarea'),
                        $this->field('due_at', 'Batas tindak lanjut', 'datetime'), $this->field('resolved_at', 'Waktu selesai', 'datetime'),
                        $this->field('resolved_by', 'Diselesaikan oleh', 'select', false, 'users'), $this->field('photo_path', 'Foto', 'file'), $this->field('notes', 'Catatan', 'textarea'),
                    ]),
                    'wasteRecords' => $this->wasteRelation(),
                ],
            ],
        ];
    }

    /** @return array<int|string, string> */
    public function options(mixed $source, int $unitId): array
    {
        if (is_array($source)) {
            return $source;
        }
        if (is_string($source) && enum_exists($source) && method_exists($source, 'options')) {
            return $source::options();
        }

        $query = match ($source) {
            'users' => User::query()->where('is_active', true)->orderBy('name'),
            'ingredients' => Ingredient::query()->where('sppg_unit_id', $unitId)->orderBy('name'),
            'measurement_units' => MeasurementUnit::query()->orderBy('name'),
            'cleaning_areas' => CleaningArea::query()->where('sppg_unit_id', $unitId)->where('is_active', true)->orderBy('name'),
            'processing_batches' => ProcessingBatch::query()->where('sppg_unit_id', $unitId)->latest('production_date'),
            'portioning_sessions' => PortioningSession::query()->where('sppg_unit_id', $unitId)->latest('portioning_date'),
            'distribution_runs' => DistributionRun::query()->where('sppg_unit_id', $unitId)->latest('distribution_date'),
            'container_collection_runs' => ContainerCollectionRun::query()->where('sppg_unit_id', $unitId)->latest('collection_date'),
            default => null,
        };

        if (! $query) {
            return [];
        }

        $label = match ($source) {
            'users', 'ingredients', 'measurement_units', 'cleaning_areas' => 'name',
            'processing_batches' => 'batch_number',
            'portioning_sessions' => 'session_number',
            'distribution_runs', 'container_collection_runs' => 'run_number',
        };

        return $query->limit(250)->pluck($label, 'id')->all();
    }

    /** @return array<string, mixed> */
    private function field(string $name, string $label, string $type = 'text', bool $required = false, mixed $options = null): array
    {
        return compact('name', 'label', 'type', 'required', 'options');
    }

    /** @return array<string, mixed> */
    private function relation(string $label, array $fields): array
    {
        return compact('label', 'fields');
    }

    /** @return array<string, mixed> */
    private function documentationRelation(): array
    {
        return $this->relation('Dokumentasi', [
            $this->field('phase', 'Tahap', 'select', true, ['before' => 'Sebelum', 'process' => 'Proses', 'after' => 'Sesudah', 'handover' => 'Serah-terima', 'other' => 'Lainnya']),
            $this->field('photo_path', 'Foto', 'file', true), $this->field('caption', 'Keterangan'),
            $this->field('captured_at', 'Waktu foto', 'datetime'), $this->field('sort_order', 'Urutan', 'number'),
        ]);
    }

    /** @return array<string, mixed> */
    private function chemicalRelation(bool $withDilution): array
    {
        $fields = [
            $this->field('chemical_name', 'Nama bahan', 'text', true), $this->field('quantity', 'Jumlah', 'number', true),
            $this->field('unit', 'Satuan', 'text', true), $this->field('purpose', 'Kegunaan'),
        ];
        if ($withDilution) {
            $fields[] = $this->field('dilution_ratio', 'Rasio pengenceran');
        }

        return $this->relation('Bahan pembersih dan sanitizer', [...$fields,
            $this->field('batch_number', 'Nomor batch'), $this->field('expiry_date', 'Kedaluwarsa', 'date'),
            $this->field('used_at', 'Waktu penggunaan', 'datetime'), $this->field('notes', 'Catatan', 'textarea'),
        ]);
    }

    /** @return array<string, mixed> */
    private function wasteRelation(): array
    {
        return $this->relation('Catatan limbah', [
            $this->field('waste_type', 'Jenis limbah', 'text', true), $this->field('quantity', 'Jumlah', 'number', true),
            $this->field('unit', 'Satuan', 'text', true), $this->field('disposal_method', 'Metode penanganan'),
            $this->field('handed_over_to', 'Diserahkan kepada'), $this->field('photo_path', 'Foto', 'file'),
            $this->field('notes', 'Catatan', 'textarea'),
        ]);
    }
}
