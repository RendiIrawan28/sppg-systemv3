<?php

namespace App\Support\Mobile;

use App\Enums\UserRole;
use App\Models\FieldDailyReport;
use App\Models\FieldIncident;
use App\Models\ContainerCollectionRun;
use App\Models\ContainerCollectionTask;
use App\Models\InventoryLot;
use App\Models\PreparationReturn;
use App\Models\PreparationOutput;
use App\Models\PreparationOutputWithdrawal;
use App\Models\PreparationSessionItem;
use App\Models\PreparationSession;
use App\Models\SecurityShift;
use App\Enums\SecuritySituation;
use App\Models\StockReceipt;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Models\WasteHandoverReport;
use App\Support\V3\OperationalModuleRegistry;

class MobileWorkspaceRegistry
{
    private const ROLE_MODULES = [
        UserRole::AsistenLapangan->value => ['lapangan-insiden', 'lapangan-laporan'],
        UserRole::StafGudang->value => ['gudang', 'gudang-stok', 'gudang-pengambilan', 'gudang-retur'],
        UserRole::KepalaDivisiPersiapan->value => ['persiapan', 'hasil-persiapan', 'ba-limbah-persiapan'],
        UserRole::PetugasPersiapan->value => ['persiapan', 'hasil-persiapan', 'ba-limbah-persiapan'],
        UserRole::KepalaDivisiPengolahan->value => ['pengolahan', 'hasil-persiapan-pengolahan'],
        UserRole::PetugasPengolahan->value => ['pengolahan', 'hasil-persiapan-pengolahan'],
        UserRole::KepalaDivisiPemorsian->value => ['pemorsian', 'hasil-persiapan-pemorsian'],
        UserRole::PetugasPemorsian->value => ['pemorsian', 'hasil-persiapan-pemorsian'],
        UserRole::KepalaDivisiDistribusi->value => ['distribusi', 'pengambilan-ompreng-tugas', 'pengambilan-ompreng'],
        UserRole::PetugasDistribusi->value => ['distribusi', 'pengambilan-ompreng-tugas', 'pengambilan-ompreng'],
        UserRole::KepalaDivisiPencucian->value => ['pencucian', 'ba-limbah-pencucian'],
        UserRole::PetugasPencucian->value => ['pencucian', 'ba-limbah-pencucian'],
        UserRole::KepalaDivisiKebersihan->value => ['kebersihan', 'ba-limbah-kebersihan'],
        UserRole::PetugasKebersihan->value => ['kebersihan', 'ba-limbah-kebersihan'],
        UserRole::Satpam->value => ['keamanan'],
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
            'keamanan' => $this->securityDefinition(),
            'lapangan-insiden' => $this->fieldIncidentDefinition(),
            'lapangan-laporan' => $this->fieldDailyReportDefinition(),
        ];

        foreach ($this->operationalRegistry->definitions() as $slug => $definition) {
            // Dokumen inti operasional dibuat oleh initializer/workflow web. Mobile hanya
            // melengkapi rincian dan menjalankan transisi status agar tidak tercipta sesi
            // tanpa sumber, snapshot, atau checklist resmi.
            if (in_array($slug, ['pengolahan', 'pemorsian', 'distribusi', 'pencucian', 'kebersihan'], true)) {
                $definition['allow_create'] = false;
                $definition['allow_delete'] = false;
            }

            $definitions[$slug] = $definition;
        }

        $definitions += [
            'hasil-persiapan' => $this->preparationOutputDefinition('preparation'),
            'hasil-persiapan-pengolahan' => $this->preparationOutputDefinition('processing'),
            'hasil-persiapan-pemorsian' => $this->preparationOutputDefinition('portioning'),
            'pengambilan-ompreng-tugas' => $this->containerCollectionTaskDefinition(),
            'pengambilan-ompreng' => $this->containerCollectionDefinition(),
            'ba-limbah-persiapan' => $this->wasteHandoverDefinition('preparation'),
            'ba-limbah-pencucian' => $this->wasteHandoverDefinition('washing'),
            'ba-limbah-kebersihan' => $this->wasteHandoverDefinition('cleaning'),
        ];

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
        if ($source === 'preparation_session_items') {
            return PreparationSessionItem::query()
                ->whereHas('session', fn ($query) => $query
                    ->where('sppg_unit_id', $unitId)
                    ->whereIn('state', ['in_progress', 'completed']))
                ->with('session:id,session_number')
                ->latest('id')
                ->limit(200)
                ->get()
                ->mapWithKeys(fn (PreparationSessionItem $item): array => [
                    (string) $item->getKey() => trim(implode(' - ', array_filter([
                        $item->session?->session_number,
                        $item->ingredient_name_snapshot,
                    ]))),
                ])
                ->all();
        }

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
                [...$this->field('receipt_date', 'Tanggal penerimaan', 'date'), 'editable' => false],
                [...$this->field('status', 'Status'), 'editable' => false],
                [...$this->field('received_by_name', 'Penerima'), 'editable' => false],
                [...$this->field('received_at', 'Waktu diterima', 'datetime'), 'editable' => false],
                $this->field('notes', 'Catatan'),
                $this->field('documentation_path', 'Foto kiriman supplier', 'file', true),
            ],
            'allow_create' => false,
            'allow_delete' => false,
            'relations' => [
                'items' => $this->relation('Barang dan pemeriksaan QC', [
                    [...$this->field('ingredient_name_snapshot', 'Bahan'), 'editable' => false],
                    [...$this->field('unit_snapshot', 'Satuan'), 'editable' => false],
                    [...$this->field('ordered_quantity', 'Dipesan', 'number'), 'editable' => false],
                    $this->field('received_quantity', 'Diterima', 'number', true),
                    $this->field('accepted_quantity', 'Lolos QC', 'number', true),
                    $this->field('rejected_quantity', 'Ditolak', 'number', true),
                    $this->field('supplier_batch_number', 'Batch supplier'),
                    $this->field('expired_date', 'Kedaluwarsa', 'date'),
                    $this->field('received_temperature_celsius', 'Suhu diterima °C', 'number'),
                    $this->field('quality_status', 'Status mutu', 'select', true, [
                        'accepted' => 'Diterima',
                        'partial' => 'Diterima sebagian',
                        'rejected' => 'Ditolak',
                    ]),
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
            'allow_create' => false,
            'allow_delete' => false,
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
                    $this->field('notes', 'Catatan'),
                ]),
                'resultDocumentation' => $this->relation('Dokumentasi hasil Persiapan', [
                    $this->field('photo_path', 'Foto hasil Persiapan', 'file', true),
                    $this->field('captured_at', 'Waktu foto', 'datetime', true),
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
    private function securityDefinition(): array
    {
        return [
            'label' => 'Keamanan',
            'description' => 'Shift 12 jam, pemeriksaan berkala, aktivitas akses, dan laporan situasi.',
            'model' => SecurityShift::class,
            'permission' => 'security',
            'number' => 'uuid',
            'date' => 'started_at',
            'fields' => [
                [...$this->field('started_at', 'Mulai shift', 'datetime'), 'editable' => false],
                [...$this->field('scheduled_end_at', 'Selesai terjadwal', 'datetime'), 'editable' => false],
                [...$this->field('next_report_due_at', 'Laporan berikutnya', 'datetime'), 'editable' => false],
                [...$this->field('completed_at', 'Selesai aktual', 'datetime'), 'editable' => false],
                [...$this->field('officer_name_snapshot', 'Petugas'), 'editable' => false],
                [...$this->field('status', 'Status'), 'editable' => false],
                [...$this->field('reports_expected', 'Target laporan', 'number'), 'editable' => false],
            ],
            'relations' => [
                'reports' => $this->relation('Laporan situasi', [
                    [...$this->field('sequence_number', 'Laporan ke', 'number'), 'editable' => false],
                    [...$this->field('reported_at', 'Waktu laporan', 'datetime'), 'editable' => false],
                    $this->field('situation', 'Situasi', 'select', true, SecuritySituation::class),
                    $this->field('gate_secure', 'Gerbang aman', 'boolean', true),
                    $this->field('perimeter_secure', 'Perimeter aman', 'boolean', true),
                    $this->field('access_activity', 'Aktivitas akses'),
                    $this->field('visitor_activity', 'Aktivitas tamu'),
                    $this->field('notes', 'Catatan'),
                    $this->field('photo_path', 'Foto bukti', 'file'),
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
            'allow_create' => false,
            'allow_update' => false,
            'allow_delete' => false,
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
            'allow_create' => false,
            'allow_update' => false,
            'allow_delete' => false,
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
                    [...$this->field('ingredient_name_snapshot', 'Bahan'), 'editable' => false],
                    [...$this->field('lot_number_snapshot', 'Nomor lot'), 'editable' => false],
                    [...$this->field('unit_snapshot', 'Satuan'), 'editable' => false],
                    [...$this->field('requested_quantity', 'Diajukan', 'number'), 'editable' => false],
                    $this->field('actual_quantity', 'Aktual', 'number', true),
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
            'allow_create' => false,
            'allow_update' => false,
            'allow_delete' => false,
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
    private function preparationOutputDefinition(string $viewer): array
    {
        $permission = match ($viewer) {
            'processing' => 'processing',
            'portioning' => 'portioning',
            default => 'preparation',
        };
        $whereIn = match ($viewer) {
            'processing' => ['processing', 'both'],
            'portioning' => ['portioning', 'both'],
            default => [],
        };

        $fields = [
            $this->field('output_name', 'Nama hasil', 'text', $viewer === 'preparation'),
            [...$this->field('source_ingredient_name_snapshot', 'Bahan asal'), 'editable' => false],
            $this->field('quantity', 'Jumlah awal', 'number', $viewer === 'preparation'),
            [...$this->field('available_quantity', 'Jumlah tersedia', 'number'), 'editable' => false],
            $this->field('unit_snapshot', 'Satuan', 'text', $viewer === 'preparation'),
            $this->field('target_division', 'Tujuan penggunaan', 'select', $viewer === 'preparation', [
                'processing' => 'Pengolahan',
                'portioning' => 'Pemorsian',
                'both' => 'Pengolahan dan Pemorsian',
            ]),
            $this->field('storage_location', 'Lokasi penyimpanan'),
            $this->field('stored_at', 'Waktu disimpan', 'datetime'),
            $this->field('expires_at', 'Batas penggunaan', 'datetime'),
            [...$this->field('state', 'Status'), 'editable' => false],
            $this->field('photo_path', 'Dokumentasi', 'file'),
            $this->field('notes', 'Catatan'),
        ];

        if ($viewer === 'preparation') {
            array_unshift(
                $fields,
                $this->field('preparation_session_item_id', 'Bahan sesi Persiapan', 'select', true, 'preparation_session_items'),
            );
        }

        $definition = [
            'label' => 'Hasil Persiapan',
            'description' => 'Bahan siap pakai yang disimpan sementara untuk Pengolahan atau Pemorsian.',
            'model' => PreparationOutput::class,
            'permission' => $permission,
            'number' => 'output_name',
            'date' => 'stored_at',
            'allow_create' => $viewer === 'preparation',
            'allow_update' => $viewer === 'preparation',
            'allow_delete' => false,
            'viewer' => $viewer,
            'fields' => $fields,
            'relations' => [
                'withdrawals' => $this->relation('Riwayat pengambilan', [
                    $this->field('destination_division', 'Divisi pengambil'),
                    $this->field('requested_quantity', 'Jumlah diminta', 'number'),
                    $this->field('verified_quantity', 'Jumlah aktual', 'number'),
                    $this->field('unit_snapshot', 'Satuan'),
                    $this->field('status', 'Status'),
                    $this->field('taken_at', 'Waktu diambil', 'datetime'),
                    $this->field('notes', 'Catatan pengambil'),
                    $this->field('review_notes', 'Catatan verifikasi'),
                ]),
            ],
        ];

        if ($whereIn !== []) {
            $definition['where_in'] = ['target_division' => $whereIn];
        }

        return $definition;
    }


    /** @return array<string, mixed> */
    private function containerCollectionTaskDefinition(): array
    {
        return [
            'label' => 'Daftar Ompreng',
            'description' => 'Daftar sekolah atau Posyandu yang omprengnya masih perlu diambil.',
            'model' => ContainerCollectionTask::class,
            'permission' => 'distribution',
            'number' => 'destination_name',
            'date' => 'delivery_date',
            'allow_create' => false,
            'allow_update' => false,
            'allow_delete' => false,
            'fields' => [
                $this->field('delivery_date', 'Tanggal pengantaran', 'date'),
                $this->field('destination_name', 'Tujuan'),
                $this->field('destination_type', 'Jenis tujuan'),
                $this->field('address', 'Alamat'),
                $this->field('contact_name', 'Kontak'),
                $this->field('contact_phone', 'Nomor telepon'),
                $this->field('target_containers', 'Target ompreng', 'number'),
                $this->field('collected_containers', 'Sudah diambil', 'number'),
                $this->field('remaining_containers', 'Belum diambil', 'number'),
                $this->field('status', 'Status'),
                $this->field('available_at', 'Mulai tersedia', 'datetime'),
                $this->field('completed_at', 'Selesai', 'datetime'),
                $this->field('notes', 'Catatan'),
            ],
            'relations' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function containerCollectionDefinition(): array
    {
        return [
            'label' => 'Pengambilan Ompreng',
            'description' => 'Kegiatan pengambilan ompreng terpisah setelah pengantaran makanan.',
            'model' => ContainerCollectionRun::class,
            'permission' => 'distribution',
            'number' => 'run_number',
            'date' => 'collection_date',
            'allow_create' => true,
            'allow_update' => false,
            'allow_delete' => false,
            'fields' => [
                [...$this->field('collection_date', 'Tanggal pengambilan', 'date'), 'editable' => false],
                [...$this->field('state', 'Status'), 'editable' => false],
                [...$this->field('driver_name_snapshot', 'Driver'), 'editable' => false],
                $this->field('kernet_name', 'Kernet'),
                $this->field('vehicle_name', 'Kendaraan'),
                $this->field('vehicle_plate', 'Nomor polisi'),
                [...$this->field('started_at', 'Mulai', 'datetime'), 'editable' => false],
                [...$this->field('returned_at', 'Kembali ke SPPG', 'datetime'), 'editable' => false],
                [...$this->field('total_collected', 'Total ompreng diambil', 'number'), 'editable' => false],
                $this->field('notes', 'Catatan'),
            ],
            'relations' => [
                'items' => $this->relation('Tujuan yang diambil', [
                    $this->field('collected_quantity', 'Jumlah diambil', 'number'),
                    $this->field('status', 'Status'),
                    $this->field('collected_at', 'Waktu diambil', 'datetime'),
                    $this->field('photo_path', 'Dokumentasi', 'file'),
                    $this->field('notes', 'Catatan'),
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function wasteHandoverDefinition(string $division): array
    {
        $permission = match ($division) {
            'washing' => 'washing',
            'cleaning' => 'cleaning',
            default => 'preparation',
        };

        return [
            'label' => 'Berita Acara Limbah',
            'description' => 'Berita acara serah-terima limbah divisi yang terhubung ke pekerjaan sumber.',
            'model' => WasteHandoverReport::class,
            'permission' => $permission,
            'number' => 'report_number',
            'date' => 'report_date',
            'allow_create' => true,
            'allow_delete' => true,
            'where' => ['division_type' => $division],
            'fields' => [
                $this->field('report_date', 'Tanggal laporan', 'date', true),
                $this->field('handed_over_at', 'Waktu serah-terima', 'datetime', true),
                $this->field('source_type', 'Jenis pekerjaan sumber', 'select', true, [
                    $division.'_session' => match ($division) {
                        'washing' => 'Sesi Pencucian',
                        'cleaning' => 'Sesi Kebersihan',
                        default => 'Sesi Persiapan',
                    },
                ]),
                $this->field('source_id', 'Pekerjaan sumber', 'select', true, $division.'_sessions'),
                $this->field('source_reference', 'Referensi pekerjaan'),
                $this->field('first_party_name', 'Pihak pertama', 'text', true),
                $this->field('first_party_position', 'Jabatan pihak pertama', 'text', true),
                $this->field('first_party_address', 'Alamat pihak pertama', 'textarea', true),
                $this->field('second_party_name', 'Pihak kedua', 'text', true),
                $this->field('second_party_position', 'Jabatan pihak kedua', 'text', true),
                $this->field('second_party_address', 'Alamat pihak kedua', 'textarea', true),
                $this->field('status', 'Status'),
                $this->field('notes', 'Catatan'),
            ],
            'relations' => [
                'items' => $this->relation('Daftar limbah', [
                    $this->field('waste_type', 'Jenis limbah', 'text', true),
                    $this->field('quantity', 'Jumlah', 'number', true),
                    $this->field('unit', 'Satuan', 'text', true),
                    $this->field('photo_path', 'Dokumentasi', 'file'),
                    $this->field('notes', 'Catatan'),
                ]),
            ],
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
            'allow_create' => false,
            'allow_delete' => false,
            'fields' => [
                [...$this->field('report_date', 'Tanggal laporan', 'date'), 'editable' => false],
                [...$this->field('status', 'Status'), 'editable' => false],
                [...$this->field('planned_beneficiaries', 'Penerima direncanakan', 'number'), 'editable' => false],
                [...$this->field('actual_beneficiaries', 'Penerima aktual', 'number'), 'editable' => false],
                [...$this->field('planned_portions', 'Porsi direncanakan', 'number'), 'editable' => false],
                [...$this->field('delivered_portions', 'Porsi terkirim', 'number'), 'editable' => false],
                [...$this->field('returned_portions', 'Porsi kembali', 'number'), 'editable' => false],
                [...$this->field('planned_destinations', 'Tujuan direncanakan', 'number'), 'editable' => false],
                [...$this->field('completed_destinations', 'Tujuan selesai', 'number'), 'editable' => false],
                [...$this->field('failed_destinations', 'Tujuan gagal', 'number'), 'editable' => false],
                [...$this->field('containers_returned', 'Ompreng kembali', 'number'), 'editable' => false],
                [...$this->field('containers_damaged', 'Ompreng rusak', 'number'), 'editable' => false],
                [...$this->field('containers_lost', 'Ompreng hilang', 'number'), 'editable' => false],
                [...$this->field('open_incidents', 'Insiden terbuka', 'number'), 'editable' => false],
                [...$this->field('resolved_incidents', 'Insiden selesai', 'number'), 'editable' => false],
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
