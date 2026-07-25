<?php

namespace App\Support\Mobile;

use App\Enums\UserRole;
use App\Models\FieldDailyReport;
use App\Models\FieldIncident;
use App\Models\InventoryLot;
use App\Models\PreparationReturn;
use App\Models\PreparationSession;
use App\Models\StockReceipt;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Support\V3\OperationalModuleRegistry;

class MobileWorkspaceRegistry
{
    private const ROLE_MODULES = [
        UserRole::AsistenLapangan->value => ['lapangan-insiden', 'lapangan-laporan'],
        UserRole::StafGudang->value => ['gudang', 'gudang-stok', 'gudang-pengambilan', 'gudang-retur'],
        UserRole::KepalaDivisiPersiapan->value => ['persiapan'],
        UserRole::PetugasPersiapan->value => ['persiapan'],
        UserRole::KepalaDivisiPengolahan->value => ['pengolahan'],
        UserRole::PetugasPengolahan->value => ['pengolahan'],
        UserRole::KepalaDivisiPemorsian->value => ['pemorsian'],
        UserRole::PetugasPemorsian->value => ['pemorsian'],
        UserRole::KepalaDivisiDistribusi->value => ['distribusi'],
        UserRole::PetugasDistribusi->value => ['distribusi'],
        UserRole::KepalaDivisiPencucian->value => ['pencucian'],
        UserRole::PetugasPencucian->value => ['pencucian'],
        UserRole::KepalaDivisiKebersihan->value => ['kebersihan'],
        UserRole::PetugasKebersihan->value => ['kebersihan'],
    ];

    public function __construct(
        private readonly OperationalModuleRegistry $operationalRegistry,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $definitions = [
            'gudang' => $this->warehouseDefinition(),
            'gudang-stok' => $this->warehouseStockDefinition(),
            'gudang-pengambilan' => $this->warehouseWithdrawalDefinition(),
            'gudang-retur' => $this->warehouseReturnDefinition(),
            'persiapan' => $this->preparationDefinition(),
            'lapangan-insiden' => $this->fieldIncidentDefinition(),
            'lapangan-laporan' => $this->fieldDailyReportDefinition(),
        ];

        foreach ($this->operationalRegistry->definitions() as $slug => $definition) {
            $definitions[$slug] = $definition;
        }

        return $definitions;
    }

    /** @return array<string, mixed> */
    public function get(string $slug): array
    {
        abort_unless(isset($this->definitions()[$slug]), 404);

        return $this->definitions()[$slug];
    }

    /** @return array<string, array<string, mixed>> */
    public function forUser(User $user): array
    {
        $roles = $user->getRoleNames();
        $privileged = $user->is_super_admin || $roles->intersect([
            UserRole::SuperAdmin->value,
            UserRole::AdminSppg->value,
            UserRole::KepalaSppg->value,
            UserRole::Viewer->value,
        ])->isNotEmpty();

        $allowedSlugs = $privileged
            ? array_keys($this->definitions())
            : $roles->flatMap(fn (string $role): array => self::ROLE_MODULES[$role] ?? [])->unique()->values()->all();

        return collect($this->definitions())
            ->only($allowedSlugs)
            ->filter(fn (array $definition): bool => $user->can($definition['permission'].'.view'))
            ->all();
    }

    public function authorize(User $user, string $slug): array
    {
        $definition = $this->get($slug);
        abort_unless(isset($this->forUser($user)[$slug]), 403);

        return $definition;
    }

    public function options(mixed $source, int $unitId): array
    {
        return $this->operationalRegistry->options($source, $unitId);
    }

    /** @return array<string, mixed> */
    private function warehouseDefinition(): array
    {
        return [
            'label' => 'Gudang',
            'description' => 'Penerimaan bahan dan pemeriksaan mutu barang masuk.',
            'model' => StockReceipt::class,
            'permission' => 'stock',
            'number' => 'receipt_number',
            'date' => 'receipt_date',
            'fields' => [
                $this->field('receipt_date', 'Tanggal penerimaan', 'date'),
                $this->field('status', 'Status'),
                $this->field('received_by_name', 'Penerima'),
                $this->field('received_at', 'Waktu diterima', 'datetime'),
                $this->field('notes', 'Catatan'),
            ],
            'relations' => [
                'items' => $this->relation('Barang dan pemeriksaan QC', [
                    $this->field('ingredient_name_snapshot', 'Bahan'),
                    $this->field('unit_snapshot', 'Satuan'),
                    $this->field('ordered_quantity', 'Dipesan', 'number'),
                    $this->field('received_quantity', 'Diterima', 'number'),
                    $this->field('accepted_quantity', 'Lolos QC', 'number'),
                    $this->field('rejected_quantity', 'Ditolak', 'number'),
                    $this->field('supplier_batch_number', 'Batch supplier'),
                    $this->field('expired_date', 'Kedaluwarsa', 'date'),
                    $this->field('received_temperature_celsius', 'Suhu diterima °C', 'number'),
                    $this->field('quality_status', 'Status mutu'),
                    $this->field('quality_notes', 'Catatan mutu'),
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function preparationDefinition(): array
    {
        return [
            'label' => 'Persiapan',
            'description' => 'Pengambilan bahan, proses persiapan, rekonsiliasi, dan laporan hasil.',
            'model' => PreparationSession::class,
            'permission' => 'preparation',
            'number' => 'session_number',
            'date' => 'preparation_date',
            'fields' => [
                $this->field('preparation_date', 'Tanggal persiapan', 'date'),
                $this->field('purpose_reference', 'Referensi kebutuhan'),
                $this->field('state', 'Tahap pekerjaan'),
                $this->field('status', 'Status laporan'),
                $this->field('petugas_id', 'Penanggung jawab', 'select', false, 'users'),
                $this->field('started_at', 'Mulai', 'datetime'),
                $this->field('completed_at', 'Selesai', 'datetime'),
                $this->field('notes', 'Catatan'),
            ],
            'relations' => [
                'items' => $this->relation('Bahan yang dipersiapkan', [
                    $this->field('ingredient_name_snapshot', 'Bahan'),
                    $this->field('unit_snapshot', 'Satuan'),
                    $this->field('received_quantity', 'Diterima', 'number'),
                    $this->field('processed_quantity', 'Hasil bersih', 'number'),
                    $this->field('waste_quantity', 'Sisa/limbah', 'number'),
                    $this->field('condition_status', 'Kondisi'),
                    $this->field('process_method', 'Metode proses'),
                    $this->field('thawing_temperature_celsius', 'Suhu thawing °C', 'number'),
                    $this->field('notes', 'Catatan'),
                ]),
                'returns' => $this->relation('Retur ke Gudang', [
                    $this->field('return_number', 'Nomor retur'),
                    $this->field('ingredient_name_snapshot', 'Bahan'),
                    $this->field('requested_quantity', 'Diajukan', 'number'),
                    $this->field('actual_quantity', 'Aktual', 'number'),
                    $this->field('condition_status', 'Kondisi'),
                    $this->field('warehouse_disposition', 'Keputusan Gudang'),
                    $this->field('status', 'Status'),
                    $this->field('reason', 'Alasan'),
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function warehouseStockDefinition(): array
    {
        return [
            'label' => 'Stok Gudang',
            'description' => 'Saldo lot, lokasi penyimpanan, status mutu, dan masa kedaluwarsa.',
            'model' => InventoryLot::class,
            'permission' => 'stock',
            'number' => 'lot_number',
            'date' => 'expired_date',
            'fields' => [
                $this->field('lot_number', 'Nomor lot'),
                $this->field('ingredient_id', 'Bahan', 'select', false, 'ingredients'),
                $this->field('unit_snapshot', 'Satuan'),
                $this->field('initial_quantity', 'Jumlah awal', 'number'),
                $this->field('balance_quantity', 'Saldo tersedia', 'number'),
                $this->field('expired_date', 'Kedaluwarsa', 'date'),
                $this->field('location_name', 'Lokasi'),
                $this->field('storage_type', 'Jenis penyimpanan'),
                $this->field('status', 'Status'),
            ],
            'relations' => [
                'movements' => $this->relation('Kartu stok', [
                    $this->field('movement_date', 'Tanggal', 'datetime'),
                    $this->field('movement_type', 'Jenis mutasi'),
                    $this->field('quantity_in', 'Jumlah masuk', 'number'),
                    $this->field('quantity_out', 'Jumlah keluar', 'number'),
                    $this->field('reference_number', 'Referensi'),
                    $this->field('notes', 'Catatan'),
                ]),
                'adjustments' => $this->relation('Penyesuaian stok', [
                    $this->field('adjustment_date', 'Tanggal', 'datetime'),
                    $this->field('system_quantity', 'Saldo sistem', 'number'),
                    $this->field('actual_quantity', 'Saldo aktual', 'number'),
                    $this->field('difference_quantity', 'Selisih', 'number'),
                    $this->field('reason', 'Alasan'),
                    $this->field('status', 'Status'),
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function warehouseWithdrawalDefinition(): array
    {
        return [
            'label' => 'Pengambilan Gudang',
            'description' => 'Permintaan pengambilan bahan dan verifikasi aktual oleh Staf Gudang.',
            'model' => WarehouseWithdrawal::class,
            'permission' => 'stock',
            'number' => 'withdrawal_number',
            'date' => 'withdrawal_date',
            'fields' => [
                $this->field('withdrawal_date', 'Tanggal', 'date'),
                $this->field('division_code', 'Divisi pemohon'),
                $this->field('reference_number_snapshot', 'Referensi'),
                $this->field('purpose_reference', 'Keperluan'),
                $this->field('shift', 'Shift'),
                $this->field('status', 'Status'),
                $this->field('submitted_at', 'Diajukan', 'datetime'),
                $this->field('verified_at', 'Diverifikasi', 'datetime'),
                $this->field('decision_notes', 'Catatan keputusan'),
                $this->field('notes', 'Catatan'),
            ],
            'relations' => [
                'items' => $this->relation('Barang yang diambil', [
                    $this->field('ingredient_name_snapshot', 'Bahan'),
                    $this->field('lot_number_snapshot', 'Nomor lot'),
                    $this->field('unit_snapshot', 'Satuan'),
                    $this->field('requested_quantity', 'Diajukan', 'number'),
                    $this->field('actual_quantity', 'Aktual', 'number'),
                    $this->field('pickup_temperature_celsius', 'Suhu pengambilan °C', 'number'),
                    $this->field('notes', 'Catatan'),
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function warehouseReturnDefinition(): array
    {
        return [
            'label' => 'Retur Persiapan',
            'description' => 'Pemeriksaan retur bahan Persiapan dan keputusan saldo Gudang.',
            'model' => PreparationReturn::class,
            'permission' => 'stock',
            'number' => 'return_number',
            'date' => 'return_date',
            'fields' => [
                $this->field('return_date', 'Tanggal retur', 'date'),
                $this->field('ingredient_name_snapshot', 'Bahan'),
                $this->field('unit_snapshot', 'Satuan'),
                $this->field('requested_quantity', 'Diajukan', 'number'),
                $this->field('actual_quantity', 'Aktual', 'number'),
                $this->field('condition_status', 'Kondisi'),
                $this->field('warehouse_disposition', 'Keputusan Gudang'),
                $this->field('status', 'Status'),
                $this->field('reason', 'Alasan'),
                $this->field('warehouse_notes', 'Catatan Gudang'),
                $this->field('verified_at', 'Diverifikasi', 'datetime'),
            ],
            'relations' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function fieldIncidentDefinition(): array
    {
        return [
            'label' => 'Insiden Lapangan',
            'description' => 'Kejadian, tindak lanjut, dan penyelesaian di lapangan.',
            'model' => FieldIncident::class,
            'permission' => 'field_incidents',
            'number' => 'uuid',
            'date' => 'incident_date',
            'fields' => [
                $this->field('incident_date', 'Tanggal', 'date'),
                $this->field('occurred_at', 'Waktu kejadian', 'datetime'),
                $this->field('division_code', 'Divisi'),
                $this->field('category', 'Kategori'),
                $this->field('severity', 'Tingkat'),
                $this->field('title', 'Judul'),
                $this->field('description', 'Deskripsi'),
                $this->field('location', 'Lokasi'),
                $this->field('responsible_name_snapshot', 'Penanggung jawab'),
                $this->field('due_at', 'Batas tindak lanjut', 'datetime'),
                $this->field('status', 'Status'),
                $this->field('immediate_action', 'Tindakan langsung'),
                $this->field('root_cause', 'Akar masalah'),
                $this->field('resolution', 'Penyelesaian'),
                $this->field('resolved_at', 'Diselesaikan', 'datetime'),
            ],
            'relations' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function fieldDailyReportDefinition(): array
    {
        return [
            'label' => 'Laporan Harian',
            'description' => 'Ringkasan otomatis rencana, distribusi, insiden, dan enam divisi.',
            'model' => FieldDailyReport::class,
            'permission' => 'field_daily_reports',
            'number' => 'report_number',
            'date' => 'report_date',
            'fields' => [
                $this->field('report_date', 'Tanggal laporan', 'date'),
                $this->field('status', 'Status'),
                $this->field('planned_beneficiaries', 'Penerima direncanakan', 'number'),
                $this->field('actual_beneficiaries', 'Penerima aktual', 'number'),
                $this->field('planned_portions', 'Porsi direncanakan', 'number'),
                $this->field('delivered_portions', 'Porsi terkirim', 'number'),
                $this->field('returned_portions', 'Porsi kembali', 'number'),
                $this->field('planned_destinations', 'Tujuan direncanakan', 'number'),
                $this->field('completed_destinations', 'Tujuan selesai', 'number'),
                $this->field('failed_destinations', 'Tujuan gagal', 'number'),
                $this->field('containers_returned', 'Ompreng kembali', 'number'),
                $this->field('containers_damaged', 'Ompreng rusak', 'number'),
                $this->field('containers_lost', 'Ompreng hilang', 'number'),
                $this->field('open_incidents', 'Insiden terbuka', 'number'),
                $this->field('resolved_incidents', 'Insiden selesai', 'number'),
                $this->field('operational_summary', 'Ringkasan operasional'),
                $this->field('obstacles', 'Hambatan'),
                $this->field('evaluation', 'Evaluasi'),
                $this->field('follow_up', 'Tindak lanjut'),
                $this->field('recommendations', 'Rekomendasi'),
            ],
            'relations' => [
                'divisions' => $this->relation('Ringkasan enam divisi', [
                    $this->field('division_name', 'Divisi'),
                    $this->field('completion_status', 'Status'),
                    $this->field('total_records', 'Total pekerjaan', 'number'),
                    $this->field('verified_records', 'Terverifikasi', 'number'),
                    $this->field('notes', 'Catatan'),
                ]),
                'incidents' => $this->relation('Insiden terkait', [
                    $this->field('severity', 'Tingkat'),
                    $this->field('title', 'Judul'),
                    $this->field('status', 'Status'),
                    $this->field('action_or_resolution', 'Tindakan/penyelesaian'),
                ]),
            ],
        ];
    }

    private function field(string $name, string $label, string $type = 'text', bool $required = false, mixed $options = null): array
    {
        return compact('name', 'label', 'type', 'required', 'options');
    }

    private function relation(string $label, array $fields): array
    {
        return compact('label', 'fields');
    }
}
