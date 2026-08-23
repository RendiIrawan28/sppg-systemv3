<?php

namespace App\Support\Mobile;

use App\Enums\FieldIncidentSeverity;
use App\Enums\FieldIncidentStatus;
use App\Enums\SecuritySituation;
use App\Enums\UserRole;
use App\Models\ContainerCollectionRun;
use App\Models\ContainerCollectionTask;
use App\Models\DailyBeneficiaryConfirmation;
use App\Models\FieldDailyReport;
use App\Models\FieldDistributionPlan;
use App\Models\FieldIncident;
use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\NonFoodItem;
use App\Models\OpeningStock;
use App\Models\PortioningSession;
use App\Models\PreparationOutput;
use App\Models\PreparationReturn;
use App\Models\PreparationSession;
use App\Models\PreparationSessionItem;
use App\Models\ProcessingBatch;
use App\Models\ProcessingMaterialUsage;
use App\Models\ProcessingReturn;
use App\Models\SecurityShift;
use App\Models\StockAdjustment;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WarehouseWithdrawal;
use App\Models\Warehouse;
use App\Models\WasteHandoverReport;
use App\Support\V3\OperationalModuleRegistry;
use Illuminate\Support\Facades\DB;

class MobileWorkspaceRegistry
{
    private const ROLE_MODULES = [
        UserRole::KepalaSppg->value => [
            'gudang', 'gudang-non-pangan', 'gudang-stok-awal', 'gudang-stok-awal-non-pangan', 'gudang-stok', 'gudang-stok-non-pangan', 'gudang-penyesuaian', 'gudang-penyesuaian-non-pangan', 'gudang-pengambilan', 'gudang-pengambilan-non-pangan', 'gudang-retur', 'gudang-retur-pengolahan',
            'persiapan', 'pengolahan', 'pemorsian', 'distribusi', 'pencucian', 'kebersihan',
            'ba-limbah-persiapan', 'ba-limbah-pencucian', 'ba-limbah-kebersihan',
            'lapangan-insiden', 'lapangan-laporan',
        ],
        UserRole::AsistenLapangan->value => ['lapangan-insiden', 'lapangan-laporan'],
        UserRole::StafGudang->value => ['gudang', 'gudang-non-pangan', 'gudang-pengambilan', 'gudang-pengambilan-non-pangan', 'gudang-retur', 'gudang-stok', 'gudang-stok-non-pangan', 'gudang-stok-awal', 'gudang-stok-awal-non-pangan', 'gudang-penyesuaian', 'gudang-penyesuaian-non-pangan'],
        UserRole::KepalaDivisiPersiapan->value => ['pengambilan-gudang-persiapan', 'pengambilan-non-pangan', 'persiapan', 'ba-limbah-persiapan', 'lapangan-insiden'],
        UserRole::PetugasPersiapan->value => ['pengambilan-gudang-persiapan', 'pengambilan-non-pangan', 'persiapan', 'ba-limbah-persiapan', 'lapangan-insiden'],
        UserRole::KepalaDivisiPengolahan->value => ['pengambilan-gudang-pengolahan', 'pengambilan-non-pangan', 'pengolahan', 'lapangan-insiden'],
        UserRole::PetugasPengolahan->value => ['pengambilan-gudang-pengolahan', 'pengambilan-non-pangan', 'pengolahan', 'lapangan-insiden'],
        UserRole::KepalaDivisiPemorsian->value => ['pengambilan-gudang-pemorsian', 'pengambilan-non-pangan', 'pemorsian', 'lapangan-insiden'],
        UserRole::PetugasPemorsian->value => ['pengambilan-gudang-pemorsian', 'pengambilan-non-pangan', 'pemorsian', 'lapangan-insiden'],
        UserRole::KepalaDivisiDistribusi->value => ['pengambilan-non-pangan', 'distribusi', 'pengambilan-ompreng-tugas', 'pengambilan-ompreng', 'lapangan-insiden'],
        UserRole::PetugasDistribusi->value => ['pengambilan-non-pangan', 'distribusi', 'pengambilan-ompreng-tugas', 'pengambilan-ompreng', 'lapangan-insiden'],
        UserRole::KepalaDivisiPencucian->value => ['pengambilan-non-pangan', 'pencucian', 'ba-limbah-pencucian', 'lapangan-insiden'],
        UserRole::PetugasPencucian->value => ['pengambilan-non-pangan', 'pencucian', 'ba-limbah-pencucian', 'lapangan-insiden'],
        UserRole::KepalaDivisiKebersihan->value => ['pengambilan-non-pangan', 'kebersihan', 'ba-limbah-kebersihan', 'lapangan-insiden'],
        UserRole::PetugasKebersihan->value => ['pengambilan-non-pangan', 'kebersihan', 'ba-limbah-kebersihan', 'lapangan-insiden'],
        UserRole::Satpam->value => ['keamanan', 'lapangan-insiden'],
    ];

    public function __construct(
        private readonly OperationalModuleRegistry $operationalRegistry,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $definitions = [
            'gudang' => $this->warehouseDefinition(),
            'gudang-non-pangan' => $this->warehouseDefinition(true),
            'gudang-pengambilan' => $this->warehouseWithdrawalDefinition(),
            'gudang-pengambilan-non-pangan' => $this->warehouseWithdrawalDefinition(true),
            'gudang-stok' => $this->warehouseStockDefinition(),
            'gudang-stok-non-pangan' => $this->warehouseStockDefinition(true),
            'gudang-penyesuaian' => $this->warehouseAdjustmentDefinition(),
            'gudang-penyesuaian-non-pangan' => $this->warehouseAdjustmentDefinition(true),
            'gudang-stok-awal' => $this->warehouseOpeningStockDefinition(),
            'gudang-stok-awal-non-pangan' => $this->warehouseOpeningStockDefinition(true),
            'gudang-retur' => $this->warehouseReturnDefinition(),
            'gudang-retur-pengolahan' => $this->warehouseProcessingReturnDefinition(),
            'pengambilan-gudang-persiapan' => $this->divisionWarehouseWithdrawalDefinition('persiapan'),
            'pengambilan-gudang-pengolahan' => $this->divisionWarehouseWithdrawalDefinition('pengolahan'),
            'pengambilan-gudang-pemorsian' => $this->divisionWarehouseWithdrawalDefinition('pemorsian'),
            'pengambilan-non-pangan' => $this->nonFoodWarehouseWithdrawalDefinition(),
            'persiapan' => $this->preparationDefinition(),
            'keamanan' => $this->securityDefinition(),
            'lapangan-konfirmasi' => $this->dailyBeneficiaryConfirmationDefinition(),
            'lapangan-insiden' => $this->fieldIncidentDefinition(),
            'lapangan-laporan' => $this->fieldDailyReportDefinition(),
        ];

        foreach ($this->operationalRegistry->definitions() as $slug => $definition) {
            // Dokumen inti operasional dibuat oleh workflow backend dari rencana aktif
            // atau pengambilan Gudang. Mobile melengkapi rincian dan menjalankan transisi
            // status agar tidak tercipta sesi tanpa sumber, snapshot, atau checklist resmi.
            if (in_array($slug, ['pengolahan', 'pemorsian', 'distribusi', 'pencucian', 'kebersihan'], true)) {
                $definition['allow_create'] = false;
                $definition['allow_delete'] = false;
            }

            if ($slug === 'pengolahan') {
                $definition['allow_create'] = true;
                $definition['description'] = 'Buat batch produksi manual, catat bahan baku aktual, hasil akhir, suhu, lalu serahkan ke Pemorsian.';
                $definition['fields'] = collect($definition['fields'])
                    ->reject(fn (array $field): bool => in_array($field['name'], ['production_date', 'product_name'], true))
                    ->map(function (array $field): array {
                        if ($field['name'] !== 'notes') {
                            $field['editable'] = false;
                        }
                        $field['detail_only'] = true;

                        return $field;
                    })->all();
                array_unshift($definition['fields'],
                    [...$this->field('production_date', 'Tanggal produksi', 'date', true), 'create_only' => true, 'detail_only' => false],
                    [...$this->field('product_name', 'Nama produk/menu', 'text', true), 'create_only' => true, 'detail_only' => false],
                );
                $definition['relations']['materialUsages']['fields'] = collect($definition['relations']['materialUsages']['fields'])
                    ->map(function (array $field): array {
                        $field['editable'] = ! in_array($field['name'], ['source_reference'], true);

                        return $field;
                    })->all();
                $definition['relations']['preparationOutputWithdrawals'] = $this->relation('Hasil Persiapan digunakan', [
                    [...$this->field('output_name', 'Nama hasil Persiapan'), 'editable' => false],
                    [...$this->field('used_quantity', 'Jumlah digunakan', 'number'), 'editable' => false],
                    [...$this->field('unit_snapshot', 'Satuan'), 'editable' => false],
                    [...$this->field('verification_status_label', 'Status pengecekan'), 'editable' => false],
                    [...$this->field('taken_at', 'Waktu diambil', 'datetime'), 'editable' => false],
                    [...$this->field('notes', 'Catatan'), 'editable' => false],
                    [...$this->field('review_notes', 'Catatan pemeriksaan'), 'editable' => false],
                ]);
                $definition['with'] = array_values(array_unique([
                    ...((array) ($definition['with'] ?? [])),
                    'preparationOutputWithdrawals.output',
                ]));
                $definition['relations']['documentations']['fields'] = collect($definition['relations']['documentations']['fields'])
                    ->map(function (array $field): array {
                        if ($field['name'] === 'output_unit') {
                            $field['type'] = 'select';
                            $field['options'] = 'processing_output_units';
                        }

                        return $field;
                    })->all();
            }

            if ($slug === 'pemorsian') {
                $definition['allow_create'] = true;
                $definition['description'] = 'Pilih rencana distribusi aktif dan mulai Pemorsian. Setelah berjalan, ambil barang dari Gudang atau hasil Persiapan.';
                $definition['fields'] = collect($definition['fields'])
                    ->map(function (array $field): array {
                        $field['detail_only'] = true;
                        if ($field['name'] !== 'notes') {
                            $field['editable'] = false;
                        }

                        return $field;
                    })->all();
                array_unshift($definition['fields'], [
                    ...$this->field('field_distribution_plan_id', 'Rencana distribusi aktif', 'select', true, 'portioning_active_plans'),
                    'create_only' => true,
                ]);
                foreach ([
                    'target_small_portions' => 'Target ompreng kecil',
                    'target_large_portions' => 'Target ompreng besar',
                ] as $fieldName => $label) {
                    $definition['fields'] = collect($definition['fields'])
                        ->map(function (array $field) use ($fieldName, $label): array {
                            if ($field['name'] === $fieldName) {
                                $field['label'] = $label;
                            }

                            return $field;
                        })->all();
                }
                $definition['fields'][] = [
                    ...$this->field('actual_small_portions', 'Ompreng kecil sudah diporsikan', 'number'),
                    'editable' => false,
                    'detail_only' => true,
                ];
                $definition['fields'][] = [
                    ...$this->field('actual_large_portions', 'Ompreng besar sudah diporsikan', 'number'),
                    'editable' => false,
                    'detail_only' => true,
                ];
                $definition['relations']['routeRecords']['label'] = 'Ompreng yang sudah diporsikan per rute';
                $definition['relations']['routeRecords']['fields'] = collect($definition['relations']['routeRecords']['fields'])
                    ->map(function (array $field): array {
                        $field['label'] = match ($field['name']) {
                            'small_portions' => 'Ompreng kecil',
                            'large_portions' => 'Ompreng besar',
                            default => $field['label'],
                        };

                        return $field;
                    })->all();
                $definition['relations']['supplies']['label'] = 'Bahan dan hasil produksi yang tersedia';
                $definition['relations']['supplies']['fields'] = collect($definition['relations']['supplies']['fields'])
                    ->map(function (array $field): array {
                        $field['editable'] = false;

                        return $field;
                    })->all();
                $definition['relations']['preparationOutputWithdrawals'] = $this->relation('Hasil Persiapan digunakan', [
                    [...$this->field('output_name', 'Nama hasil Persiapan'), 'editable' => false],
                    [...$this->field('used_quantity', 'Jumlah digunakan', 'number'), 'editable' => false],
                    [...$this->field('unit_snapshot', 'Satuan'), 'editable' => false],
                    [...$this->field('verification_status_label', 'Status pengecekan'), 'editable' => false],
                    [...$this->field('taken_at', 'Waktu diambil', 'datetime'), 'editable' => false],
                    [...$this->field('notes', 'Catatan'), 'editable' => false],
                    [...$this->field('review_notes', 'Catatan pemeriksaan'), 'editable' => false],
                ]);
                $definition['with'] = array_values(array_unique([
                    ...((array) ($definition['with'] ?? [])),
                    'preparationOutputWithdrawals.output',
                ]));
            }

            if ($slug === 'distribusi') {
                // Rute dan target berasal dari rencana Asisten Lapangan. Driver hanya
                // menjalankan aksi perjalanan; identitas rute tidak boleh diedit lewat
                // formulir generik mobile.
                $definition['allow_update'] = false;
                $definition['fields'] = collect($definition['fields'])
                    ->map(fn (array $field): array => [
                        ...$field,
                        'editable' => false,
                        'detail_only' => true,
                    ])->all();

                $lockedStopFields = [
                    'route_name', 'destination_name', 'sequence_order', 'planned_arrival_at',
                    'address', 'contact_name', 'contact_phone', 'small_portions',
                    'large_portions', 'status',
                ];
                $definition['relations']['stops']['fields'] = collect($definition['relations']['stops']['fields'])
                    ->map(function (array $field) use ($lockedStopFields): array {
                        if (in_array($field['name'], $lockedStopFields, true)) {
                            $field['editable'] = false;
                        }

                        return $field;
                    })->all();
            }

            if ($slug === 'pencucian') {
                // Sesi dibuat otomatis saat pengambilan ompreng kembali. Mobile hanya
                // menjalankan tahapan yang sama dengan halaman Pencucian website.
                $definition['allow_update'] = false;
                $definition['fields'] = collect($definition['fields'])
                    ->map(fn (array $field): array => [
                        ...$field,
                        'editable' => false,
                        'detail_only' => true,
                    ])->all();
                $definition['relations']['checklistItems']['fields'] = collect(
                    $definition['relations']['checklistItems']['fields'],
                )->map(fn (array $field): array => [
                    ...$field,
                    'editable' => false,
                ])->all();
                $definition['relations']['documentations']['fields'] = collect(
                    $definition['relations']['documentations']['fields'],
                )->map(function (array $field): array {
                    if (in_array($field['name'], ['phase', 'captured_at', 'sort_order'], true)) {
                        $field['editable'] = false;
                    }

                    return $field;
                })->all();
                $definition['with'] = array_values(array_unique([
                    ...((array) ($definition['with'] ?? [])),
                    'containerCollectionRun', 'distributionRun', 'wasteHandoverReport',
                ]));
            }

            $definitions[$slug] = $definition;
        }

        $definitions += [
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
        if ($source === 'processing_active_plans') {
            return FieldDistributionPlan::query()
                ->with('processingBatch')
                ->where('sppg_unit_id', $unitId)
                ->where('status', 'activated')
                ->latest('production_date')
                ->latest('id')
                ->get()
                ->filter(fn (FieldDistributionPlan $plan): bool => ! $plan->processingBatch
                    || in_array((string) $plan->processingBatch->state->value, ['planned', 'in_progress'], true))
                ->mapWithKeys(fn (FieldDistributionPlan $plan): array => [
                    (string) $plan->getKey() => implode(' · ', array_filter([
                        $plan->plan_number,
                        $plan->menu_name_snapshot,
                        ($plan->production_date ?: $plan->distribution_date)?->format('d-m-Y'),
                        $plan->processingBatch?->state?->value === 'in_progress' ? 'sedang diproses' : null,
                    ])),
                ])->all();
        }

        if ($source === 'portioning_active_plans') {
            return FieldDistributionPlan::query()
                ->with(['portioningSession', 'destinations'])
                ->where('sppg_unit_id', $unitId)
                ->where('status', 'activated')
                ->where('planned_total_portions', '>', 0)
                ->latest('distribution_date')
                ->latest('id')
                ->get()
                ->filter(fn (FieldDistributionPlan $plan): bool => $plan->destinations->isNotEmpty()
                    && (! $plan->portioningSession
                        || in_array($plan->portioningSession->state->value, ['planned', 'in_progress'], true)))
                ->mapWithKeys(fn (FieldDistributionPlan $plan): array => [
                    (string) $plan->getKey() => implode(' · ', array_filter([
                        $plan->plan_number,
                        $plan->menu_name_snapshot,
                        $plan->distribution_date?->format('d-m-Y'),
                        $plan->portioningSession?->state?->value === 'in_progress' ? 'sedang diporsikan' : null,
                    ])),
                ])->all();
        }

        if ($source === 'processing_output_units') {
            $defaults = collect([
                'pack' => 'Pack',
                'loyang' => 'Loyang',
                'pcs' => 'Pcs',
                'porsi' => 'Porsi',
            ]);
            $masterUnits = MeasurementUnit::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(function (MeasurementUnit $unit): array {
                    $value = trim((string) ($unit->symbol ?: $unit->code ?: $unit->name));

                    return [$value => $unit->name.($value !== $unit->name ? ' ('.$value.')' : '')];
                });

            return $defaults->union($masterUnits)->all();
        }

        if ($source === 'preparation_session_items') {
            return PreparationSessionItem::query()
                ->whereHas('session', fn ($query) => $query
                    ->where('sppg_unit_id', $unitId)
                    ->whereIn('state', ['in_progress', 'completed']))
                ->where('processed_quantity', '>', 0)
                ->with('session:id,session_number')
                ->latest('id')
                ->limit(200)
                ->get()
                ->mapWithKeys(fn (PreparationSessionItem $item): array => [
                    (string) $item->getKey() => trim(implode(' - ', array_filter([
                        $item->session?->session_number,
                        $item->ingredient_name_snapshot,
                        rtrim(rtrim(number_format((float) $item->processed_quantity, 4, '.', ''), '0'), '.').' '.$item->unit_snapshot,
                    ]))),
                ])
                ->all();
        }

        if ($source === 'preparation_sessions') {
            return PreparationSession::query()
                ->where('sppg_unit_id', $unitId)
                ->whereIn('state', ['planned', 'in_progress', 'completed'])
                ->latest('preparation_date')
                ->latest('id')
                ->limit(200)
                ->pluck('session_number', 'id')
                ->all();
        }

        if ($source === 'processing_material_usages_returnable') {
            return ProcessingMaterialUsage::query()
                ->whereHas('batch', fn ($query) => $query
                    ->where('sppg_unit_id', $unitId)
                    ->where('state', 'in_progress'))
                ->with(['batch:id,batch_number', 'returns:id,processing_material_usage_id,requested_quantity,actual_quantity,status'])
                ->latest('id')
                ->limit(250)
                ->get()
                ->filter(function (ProcessingMaterialUsage $usage): bool {
                    $returned = $usage->returns
                        ->whereIn('status', [ProcessingReturn::WAITING, ProcessingReturn::VERIFIED])
                        ->sum(fn (ProcessingReturn $return): float => (float) ($return->actual_quantity ?: $return->requested_quantity));

                    return (float) $usage->quantity - $returned > 0.0001;
                })
                ->mapWithKeys(function (ProcessingMaterialUsage $usage): array {
                    $returned = $usage->returns
                        ->whereIn('status', [ProcessingReturn::WAITING, ProcessingReturn::VERIFIED])
                        ->sum(fn (ProcessingReturn $return): float => (float) ($return->actual_quantity ?: $return->requested_quantity));
                    $remaining = max(0, (float) $usage->quantity - $returned);

                    return [(string) $usage->getKey() => sprintf(
                        '%s · %s · sisa %s %s',
                        $usage->batch?->batch_number ?: 'Batch',
                        $usage->material_name,
                        rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.'),
                        $usage->unit_name,
                    )];
                })
                ->all();
        }

        if ($source === 'inventory_lots_available') {
            return InventoryLot::query()
                ->with('ingredient')
                ->where('sppg_unit_id', $unitId)
                ->where('status', InventoryLot::AVAILABLE)
                ->where('balance_quantity', '>', 0)
                ->where(fn ($query) => $query->whereNull('expired_date')->orWhereDate('expired_date', '>=', today()))
                ->orderByRaw('expired_date IS NULL')
                ->orderBy('expired_date')
                ->limit(250)
                ->get()
                ->mapWithKeys(fn (InventoryLot $lot): array => [
                    (string) $lot->getKey() => sprintf(
                        '%s · lot %s · saldo %s %s',
                        $lot->ingredient?->name ?: 'Bahan',
                        $lot->lot_number,
                        rtrim(rtrim(number_format((float) $lot->balance_quantity, 4, '.', ''), '0'), '.'),
                        $lot->unit_snapshot,
                    ),
                ])->all();
        }

        if ($source === 'non_food_inventory_lots_available') {
            $warehouse = Warehouse::forUnit($unitId, Warehouse::TYPE_NON_FOOD);

            return InventoryLot::query()
                ->with('nonFoodItem')
                ->where('sppg_unit_id', $unitId)
                ->where('warehouse_id', $warehouse->getKey())
                ->whereNotNull('non_food_item_id')
                ->where('status', InventoryLot::AVAILABLE)
                ->where('balance_quantity', '>', 0)
                ->where(fn ($query) => $query->whereNull('expired_date')->orWhereDate('expired_date', '>=', today()))
                ->orderByRaw('expired_date IS NULL')
                ->orderBy('expired_date')
                ->orderBy('id')
                ->limit(250)
                ->get()
                ->filter(function (InventoryLot $lot): bool {
                    $reserved = DB::table('warehouse_withdrawal_items')
                        ->join('warehouse_withdrawals', 'warehouse_withdrawals.id', '=', 'warehouse_withdrawal_items.warehouse_withdrawal_id')
                        ->where('warehouse_withdrawal_items.inventory_lot_id', $lot->getKey())
                        ->where('warehouse_withdrawals.status', WarehouseWithdrawal::WAITING)
                        ->sum('warehouse_withdrawal_items.requested_quantity');

                    return (float) $lot->balance_quantity - (float) $reserved > 0.0001;
                })
                ->mapWithKeys(function (InventoryLot $lot): array {
                    $reserved = (float) DB::table('warehouse_withdrawal_items')
                        ->join('warehouse_withdrawals', 'warehouse_withdrawals.id', '=', 'warehouse_withdrawal_items.warehouse_withdrawal_id')
                        ->where('warehouse_withdrawal_items.inventory_lot_id', $lot->getKey())
                        ->where('warehouse_withdrawals.status', WarehouseWithdrawal::WAITING)
                        ->sum('warehouse_withdrawal_items.requested_quantity');
                    $available = max(0, (float) $lot->balance_quantity - $reserved);

                    return [(string) $lot->getKey() => sprintf(
                        '%s · lot %s · tersedia %s %s',
                        $lot->nonFoodItem?->name ?: 'Barang Non-Pangan',
                        $lot->lot_number,
                        rtrim(rtrim(number_format($available, 4, '.', ''), '0'), '.'),
                        $lot->unit_snapshot,
                    )];
                })->all();
        }

        if ($source === 'stock_receipt_items') {
            return StockReceiptItem::query()
                ->whereHas('receipt', fn ($query) => $query
                    ->where('sppg_unit_id', $unitId)
                    ->where('status', StockReceipt::STATUS_DRAFT))
                ->with('receipt:id,receipt_number')
                ->latest('id')
                ->limit(300)
                ->get()
                ->mapWithKeys(fn (StockReceiptItem $item): array => [
                    (string) $item->getKey() => ($item->receipt?->receipt_number ?: 'Penerimaan').' · '.$item->ingredient_name_snapshot,
                ])->all();
        }

        if ($source === 'active_suppliers') {
            return Supplier::query()
                ->where('sppg_unit_id', $unitId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Supplier $supplier): array => [
                    (string) $supplier->getKey() => $supplier->name.($supplier->code ? ' · '.$supplier->code : ''),
                ])->all();
        }

        if (in_array($source, ['manual_receipt_catalog', 'manual_receipt_non_food_catalog'], true)) {
            $items = $source === 'manual_receipt_non_food_catalog'
                ? NonFoodItem::query()->with('measurementUnit')->where('sppg_unit_id', $unitId)
                    ->where('is_active', true)->orderBy('name')->get()
                    ->mapWithKeys(fn (NonFoodItem $item): array => [
                        'non_food_item:'.$item->getKey() => $item->name.' · '.($item->code ?: 'tanpa kode').' · '.($item->measurementUnit?->symbol ?: $item->measurementUnit?->code ?: 'unit'),
                    ])
                : Ingredient::query()->with('measurementUnit')->where('sppg_unit_id', $unitId)
                    ->where('is_active', true)->orderBy('name')->get()
                    ->mapWithKeys(fn (Ingredient $item): array => [
                        'ingredient:'.$item->getKey() => $item->name.' · '.($item->code ?: 'tanpa kode').' · '.($item->measurementUnit?->symbol ?: $item->measurementUnit?->code ?: 'unit'),
                    ]);

            return $items->all();
        }

        if ($source === 'opening_stock_catalog') {
            $ingredients = Ingredient::query()
                ->with('measurementUnit')
                ->where('sppg_unit_id', $unitId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn ($ingredient): array => [
                    'ingredient:'.$ingredient->getKey() => $ingredient->name.' · '.($ingredient->measurementUnit?->symbol ?: $ingredient->measurementUnit?->code ?: '-'),
                ]);
            $units = MeasurementUnit::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn ($unit): array => [
                    'unit:'.$unit->getKey() => $unit->name.' ('.($unit->symbol ?: $unit->code).')',
                ]);
            $categories = collect([
                'staple' => 'Makanan Pokok', 'animal_protein' => 'Protein Hewani', 'plant_protein' => 'Protein Nabati',
                'vegetable' => 'Sayuran', 'fruit' => 'Buah', 'seasoning' => 'Bumbu', 'oil' => 'Minyak dan Lemak',
                'drink' => 'Minuman', 'dairy' => 'Susu dan Olahan', 'processed' => 'Bahan Olahan', 'other' => 'Lainnya',
            ])->mapWithKeys(fn (string $label, string $key): array => ['category:'.$key => $label]);
            $storages = collect([
                'dry' => 'Gudang kering', 'wet' => 'Gudang basah', 'freezer' => 'Freezer', 'chiller' => 'Chiller',
            ])->mapWithKeys(fn (string $label, string $key): array => ['storage:'.$key => $label]);

            return $ingredients->union($units)->union($categories)->union($storages)->all();
        }

        if ($source === 'opening_stock_non_food_catalog') {
            $items = NonFoodItem::query()
                ->with('measurementUnit')
                ->where('sppg_unit_id', $unitId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (NonFoodItem $item): array => [
                    'non_food_item:'.$item->getKey() => $item->name.' · '.($item->measurementUnit?->symbol ?: $item->measurementUnit?->code ?: '-'),
                ]);
            $storages = collect(['dry' => 'Gudang Non-Pangan'])
                ->mapWithKeys(fn (string $label, string $key): array => ['storage:'.$key => $label]);

            return $items->union($storages)->all();
        }

        if (in_array($source, ['withdrawal_references_persiapan', 'withdrawal_references_pengolahan', 'withdrawal_references_pemorsian'], true)) {
            $division = str_replace('withdrawal_references_', '', $source);
            $plans = FieldDistributionPlan::query()
                ->where('sppg_unit_id', $unitId)
                ->where('status', 'activated')
                ->where('planned_total_portions', '>', 0)
                ->orderByDesc('production_date')
                ->orderByDesc('id')
                ->limit(100)
                ->get();

            if ($division === 'persiapan') {
                return $plans->mapWithKeys(fn (FieldDistributionPlan $plan): array => [
                    'record:'.$plan->getKey() => $plan->plan_number.' · '.$plan->menu_name_snapshot,
                ])->all();
            }

            $records = $division === 'pengolahan'
                ? ProcessingBatch::query()->where('sppg_unit_id', $unitId)->where('state', 'in_progress')
                    ->latest('production_date')->limit(100)->get()->mapWithKeys(fn (ProcessingBatch $record): array => [
                        'record:'.$record->getKey() => $record->batch_number.' · '.$record->product_name,
                    ])
                : PortioningSession::query()->where('sppg_unit_id', $unitId)->where('state', 'in_progress')
                    ->latest('portioning_date')->limit(100)->get()->mapWithKeys(fn (PortioningSession $record): array => [
                        'record:'.$record->getKey() => $record->session_number.' · '.$record->menu_name_snapshot,
                    ]);

            $linkedPlanIds = $division === 'pengolahan'
                ? ProcessingBatch::query()->where('sppg_unit_id', $unitId)->whereNotNull('field_distribution_plan_id')->pluck('field_distribution_plan_id')
                : PortioningSession::query()->where('sppg_unit_id', $unitId)->whereNotNull('field_distribution_plan_id')->pluck('field_distribution_plan_id');

            $fallback = in_array($division, ['pengolahan', 'pemorsian'], true) ? collect() : $plans->whereNotIn('id', $linkedPlanIds)->mapWithKeys(fn (FieldDistributionPlan $plan): array => [
                'plan:'.$plan->getKey() => $plan->plan_number.' · '.$plan->menu_name_snapshot.' (buat otomatis)',
            ]);

            return $records->union($fallback)->all();
        }

        return $this->operationalRegistry->options($source, $unitId);
    }

    /** @return array<string, mixed> */
    private function dailyBeneficiaryConfirmationDefinition(): array
    {
        return [
            'label' => 'Konfirmasi Penerima Harian',
            'description' => 'Catat jumlah aktual penerima harian sebagai laporan lapangan yang berdiri sendiri.',
            'model' => DailyBeneficiaryConfirmation::class,
            'permission' => 'daily_beneficiary_confirmations',
            'number' => 'destination_name_snapshot',
            'date' => 'service_date',
            'allow_create' => true,
            // Satu tanggal menghasilkan beberapa tujuan sekaligus. Penghapusan satu tujuan
            // dapat membuat sekolah/Posyandu hilang dari rencana, sehingga koreksi dilakukan
            // melalui jumlah aktual atau regenerasi tanggal, bukan delete per record.
            'allow_delete' => false,
            'fields' => [
                [...$this->field('service_date', 'Tanggal pelayanan', 'date', true), 'create_only' => true],
                [...$this->field('destination_name_snapshot', 'Sekolah/Posyandu'), 'editable' => false],
                [...$this->field('destination_code_snapshot', 'Kode tujuan'), 'editable' => false],
                [...$this->field('status', 'Status'), 'editable' => false],
                [...$this->field('confirmed_at', 'Dikonfirmasi', 'datetime'), 'editable' => false],
                [...$this->field('confirmed_by_name', 'Dikonfirmasi oleh'), 'editable' => false],
                $this->field('notes', 'Catatan', 'textarea'),
            ],
            'relations' => [
                'items' => $this->relation('Jumlah aktual per kategori', [
                    [...$this->field('beneficiary_category_name_snapshot', 'Kategori'), 'editable' => false],
                    [...$this->field('portion_category', 'Jenis porsi'), 'editable' => false],
                    [...$this->field('master_count', 'Jumlah master', 'number'), 'editable' => false],
                    $this->field('actual_count', 'Jumlah aktual', 'number', true),
                    $this->field('change_reason', 'Alasan perubahan', 'textarea'),
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function divisionWarehouseWithdrawalDefinition(string $division): array
    {
        $permission = match ($division) {
            'pengolahan' => 'processing',
            'pemorsian' => 'portioning',
            default => 'preparation',
        };
        $label = match ($division) {
            'pengolahan' => 'Pengambilan Gudang - Pengolahan',
            'pemorsian' => 'Pengambilan Gudang - Pemorsian',
            default => 'Pengambilan Gudang - Persiapan',
        };

        return [
            'label' => $label,
            'description' => 'Buat catatan, tambahkan seluruh bahan dan foto, lalu kirim ke Gudang untuk verifikasi stok.',
            'model' => WarehouseWithdrawal::class,
            'permission' => $permission,
            'number' => 'withdrawal_number',
            'date' => 'withdrawal_date',
            'where' => ['division_code' => $division],
            'mobile_division' => $division,
            'allow_create' => true,
            'allow_update' => true,
            'allow_delete' => true,
            'fields' => [
                [...$this->field('reference_selection', 'Referensi pekerjaan', 'select', true, 'withdrawal_references_'.$division), 'create_only' => true],
                [...$this->field('withdrawal_date', 'Tanggal pengambilan', 'date'), 'editable' => false],
                [...$this->field('withdrawal_number', 'Nomor pengambilan'), 'editable' => false],
                [...$this->field('reference_number_snapshot', 'Referensi aktif'), 'editable' => false],
                $this->field('purpose_reference', 'Keperluan'),
                $this->field('shift', 'Shift'),
                [...$this->field('status', 'Status'), 'editable' => false],
                [...$this->field('decision_notes', 'Catatan Gudang'), 'editable' => false],
                $this->field('notes', 'Catatan', 'textarea'),
            ],
            'relations' => [
                'items' => $this->relation('Bahan yang diambil', [
                    $this->field('inventory_lot_id', 'Lot bahan', 'select', true, 'inventory_lots_available'),
                    [...$this->field('ingredient_name_snapshot', 'Bahan'), 'editable' => false],
                    [...$this->field('lot_number_snapshot', 'Nomor lot'), 'editable' => false],
                    [...$this->field('unit_snapshot', 'Satuan'), 'editable' => false],
                    $this->field('requested_quantity', 'Jumlah diambil', 'number', true),
                    $this->field('pickup_temperature_celsius', 'Suhu pengambilan °C', 'number'),
                    $this->field('photo_path', 'Foto pengambilan', 'file', true),
                    $this->field('notes', 'Catatan', 'textarea'),
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function nonFoodWarehouseWithdrawalDefinition(): array
    {
        return [
            'label' => 'Pengambilan Non-Pangan',
            'description' => 'Catat barang operasional yang langsung diambil dari Gudang Non-Pangan.',
            'model' => WarehouseWithdrawal::class,
            'permission' => 'non_food_stock',
            'number' => 'withdrawal_number',
            'date' => 'withdrawal_date',
            'warehouse_type' => Warehouse::TYPE_NON_FOOD,
            'where' => ['reference_type' => 'non_food_operational'],
            'allow_create' => true,
            'allow_update' => true,
            'allow_delete' => true,
            'fields' => [
                [...$this->field('withdrawal_date', 'Tanggal pengambilan', 'date'), 'editable' => false],
                [...$this->field('withdrawal_number', 'Nomor pengambilan'), 'editable' => false],
                [...$this->field('division_code', 'Divisi'), 'editable' => false],
                $this->field('purpose_reference', 'Keperluan', 'text', true),
                [...$this->field('status', 'Status'), 'editable' => false],
                [...$this->field('decision_notes', 'Catatan Gudang'), 'editable' => false],
                $this->field('notes', 'Catatan', 'textarea'),
            ],
            'relations' => [
                'items' => $this->relation('Barang Non-Pangan yang diambil', [
                    $this->field('inventory_lot_id', 'Barang tersedia', 'select', true, 'non_food_inventory_lots_available'),
                    [...$this->field('ingredient_name_snapshot', 'Barang'), 'editable' => false],
                    [...$this->field('lot_number_snapshot', 'Nomor lot'), 'editable' => false],
                    [...$this->field('unit_snapshot', 'Satuan'), 'editable' => false],
                    $this->field('requested_quantity', 'Jumlah diambil', 'number', true),
                    $this->field('photo_path', 'Foto pengambilan', 'file', true),
                    $this->field('notes', 'Catatan', 'textarea'),
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function warehouseDefinition(bool $nonFood = false): array
    {
        return [
            'label' => $nonFood ? 'Penerimaan Non-Pangan' : 'Penerimaan Pangan',
            'description' => 'Catat jumlah barang supplier, hasil pemeriksaan, dan dokumentasi penerimaan.',
            'model' => StockReceipt::class,
            'permission' => $nonFood ? 'non_food_stock' : 'stock',
            'warehouse_type' => $nonFood ? Warehouse::TYPE_NON_FOOD : Warehouse::TYPE_FOOD,
            'number' => 'receipt_number',
            'date' => 'receipt_date',
            'fields' => [
                [...$this->field('receipt_date', 'Tanggal penerimaan', 'date', true), 'create_only' => true],
                [...$this->field('supplier_id', 'Supplier', 'select', true, 'active_suppliers'), 'create_only' => true],
                [...$this->field(
                    'manual_rows_payload',
                    'Daftar barang penerimaan manual',
                    'manual_receipt_rows',
                    true,
                    $nonFood ? 'manual_receipt_non_food_catalog' : 'manual_receipt_catalog',
                ), 'create_only' => true],
                [...$this->field('status', 'Status'), 'editable' => false],
                [...$this->field('received_by_name', 'Penerima'), 'editable' => false],
                [...$this->field('received_at', 'Waktu diterima', 'datetime'), 'editable' => false],
                $this->field('notes', 'Catatan'),
            ],
            'allow_create' => true,
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
                    [...$this->field('quality_status', 'Status mutu', 'select', false, [
                        'accepted' => 'Diterima',
                        'partial' => 'Diterima sebagian',
                        'rejected' => 'Ditolak',
                        'pending' => 'Belum diperiksa',
                    ]), 'editable' => false],
                    $this->field('quality_notes', 'Catatan mutu'),
                ]),
                'itemPhotos' => $this->relation('Dokumentasi per Barang', [
                    $this->field('stock_receipt_item_id', 'Barang penerimaan', 'select', true, 'stock_receipt_items'),
                    [...$this->field('item_name_snapshot', 'Nama barang'), 'editable' => false],
                    $this->field('photo_path', 'Foto barang', 'file', true),
                    [...$this->field('original_name', 'Nama file'), 'editable' => false],
                    [...$this->field('created_at', 'Diunggah'), 'editable' => false],
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function warehouseOpeningStockDefinition(bool $nonFood = false): array
    {
        return [
            'label' => $nonFood ? 'Input Stok Awal Non-Pangan' : 'Input Stok Awal Pangan',
            'description' => 'Masukkan barang gudang yang sudah tersedia; stok langsung aktif setelah disimpan.',
            'model' => OpeningStock::class,
            'permission' => $nonFood ? 'non_food_stock' : 'stock',
            'warehouse_type' => $nonFood ? Warehouse::TYPE_NON_FOOD : Warehouse::TYPE_FOOD,
            'number' => 'opening_number',
            'date' => 'opening_date',
            'allow_create' => true,
            'allow_update' => false,
            'allow_delete' => false,
            'fields' => [
                $this->field('opening_date', 'Tanggal stok awal', 'date', true),
                [...$this->field('opening_number', 'Nomor stok awal'), 'editable' => false],
                [...$this->field('status', 'Status'), 'editable' => false],
                $this->field('notes', 'Catatan', 'textarea'),
                $this->field('photo_path', 'Foto keseluruhan barang', 'file', true),
                [...$this->field(
                    'rows_payload',
                    'Daftar barang',
                    'opening_stock_rows',
                    true,
                    $nonFood ? 'opening_stock_non_food_catalog' : 'opening_stock_catalog',
                ), 'create_only' => true],
            ],
            'relations' => [
                'items' => $this->relation('Barang stok awal', [
                    [...$this->field('ingredient_name_snapshot', 'Barang'), 'editable' => false],
                    [...$this->field('quantity', 'Jumlah', 'number'), 'editable' => false],
                    [...$this->field('unit_snapshot', 'Satuan'), 'editable' => false],
                    [...$this->field('lot_number', 'Nomor lot'), 'editable' => false],
                    [...$this->field('expired_date', 'Kedaluwarsa', 'date'), 'editable' => false],
                    [...$this->field('storage_type', 'Penyimpanan'), 'editable' => false],
                    [...$this->field('location_name', 'Lokasi'), 'editable' => false],
                    [...$this->field('condition_notes', 'Catatan kondisi'), 'editable' => false],
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
            'with' => ['wasteHandoverReport'],
            'fields' => [
                [...$this->field('preparation_date', 'Tanggal persiapan', 'date'), 'editable' => false],
                [...$this->field('purpose_reference', 'Referensi kebutuhan'), 'editable' => false],
                [...$this->field('state', 'Tahap pekerjaan'), 'editable' => false],
                [...$this->field('status', 'Status laporan'), 'editable' => false],
                [...$this->field('petugas_id', 'Penanggung jawab', 'select', false, 'users'), 'editable' => false],
                [...$this->field('started_at', 'Mulai', 'datetime'), 'editable' => false],
                [...$this->field('completed_at', 'Selesai', 'datetime'), 'editable' => false],
                [...$this->field('waste_report_number', 'Berita acara limbah'), 'editable' => false],
                [...$this->field('waste_report_status', 'Status berita acara limbah'), 'editable' => false],
                $this->field('notes', 'Catatan sesi', 'textarea'),
            ],
            'relations' => [
                'items' => $this->relation('Bahan yang dipersiapkan', [
                    [...$this->field('ingredient_name_snapshot', 'Bahan'), 'editable' => false],
                    [...$this->field('unit_snapshot', 'Satuan'), 'editable' => false],
                    [...$this->field('received_quantity', 'Diterima', 'number'), 'editable' => false],
                    $this->field('condition_status', 'Kondisi', 'select', true, ['good' => 'Baik', 'fair' => 'Sedang', 'damaged' => 'Rusak']),
                    $this->field('processed_quantity', 'Hasil siap', 'number'),
                    $this->field('waste_quantity', 'Limbah', 'number'),
                    $this->field('output_target_division', 'Tujuan hasil siap', 'select', true, ['processing' => 'Pengolahan', 'portioning' => 'Pemorsian']),
                    $this->field('result_photo_path', 'Foto hasil bahan', 'file'),
                    $this->field('notes', 'Catatan bahan', 'textarea'),
                ]),
                'returns' => $this->relation('Retur ke Gudang', [
                    $this->field('preparation_session_item_id', 'Bahan yang diretur', 'select', true, 'preparation_session_items'),
                    [...$this->field('return_number', 'Nomor retur'), 'editable' => false],
                    [...$this->field('ingredient_name_snapshot', 'Bahan'), 'editable' => false],
                    $this->field('requested_quantity', 'Jumlah retur', 'number', true),
                    [...$this->field('actual_quantity', 'Aktual diterima Gudang', 'number'), 'editable' => false],
                    $this->field('condition_status', 'Kondisi', 'select', true, [
                        'good' => 'Baik/tidak digunakan',
                        'damaged' => 'Rusak',
                        'rejected' => 'Ditolak',
                    ]),
                    [...$this->field('warehouse_disposition', 'Keputusan Gudang'), 'editable' => false],
                    [...$this->field('status', 'Status'), 'editable' => false],
                    $this->field('reason', 'Alasan', 'textarea', true),
                    $this->field('photo_path', 'Foto retur', 'file', true),
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
    private function warehouseStockDefinition(bool $nonFood = false): array
    {
        return [
            'label' => $nonFood ? 'Kartu Stok Non-Pangan' : 'Kartu Stok Pangan',
            'description' => 'Lihat saldo barang, lokasi penyimpanan, masa kedaluwarsa, dan riwayat mutasi.',
            'model' => InventoryLot::class,
            'with' => $nonFood ? ['nonFoodItem'] : ['ingredient'],
            'permission' => $nonFood ? 'non_food_stock' : 'stock',
            'warehouse_type' => $nonFood ? Warehouse::TYPE_NON_FOOD : Warehouse::TYPE_FOOD,
            'number' => 'lot_number',
            'date' => 'expired_date',
            'allow_create' => false,
            'allow_update' => false,
            'allow_delete' => false,
            'fields' => [
                $this->field('lot_number', 'Nomor lot'),
                $nonFood
                    ? [...$this->field('non_food_item_id', 'Barang Non-Pangan'), 'editable' => false]
                    : $this->field('ingredient_id', 'Bahan', 'select', false, 'ingredients'),
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
    private function warehouseAdjustmentDefinition(bool $nonFood = false): array
    {
        return [
            'label' => $nonFood ? 'Kontrol Stok Non-Pangan' : 'Kontrol Stok Pangan',
            'description' => 'Periksa dan verifikasi penyesuaian antara saldo sistem dan stok fisik.',
            'model' => StockAdjustment::class,
            'permission' => $nonFood ? 'non_food_stock' : 'stock',
            'warehouse_type' => $nonFood ? Warehouse::TYPE_NON_FOOD : Warehouse::TYPE_FOOD,
            'number' => 'adjustment_number',
            'date' => 'adjustment_date',
            'allow_create' => true,
            'allow_update' => false,
            'allow_delete' => false,
            'fields' => [
                [...$this->field('inventory_lot_id', 'Barang dan lot', 'select', true, $nonFood ? 'non_food_inventory_lots_available' : 'inventory_lots_available'), 'create_only' => true],
                [...$this->field('adjustment_date', 'Tanggal', 'date'), 'editable' => false],
                [...$this->field('adjustment_number', 'Nomor penyesuaian'), 'editable' => false],
                [...$this->field('unit_snapshot', 'Satuan'), 'editable' => false],
                [...$this->field('type', 'Jenis kontrol', 'select', true, [
                    'stock_opname' => 'Stok opname',
                    'return_from_division' => 'Retur dari divisi',
                    'damage' => 'Barang rusak',
                ]), 'create_only' => true],
                [...$this->field('system_quantity', 'Saldo sistem', 'number'), 'editable' => false],
                [...$this->field('actual_quantity', 'Jumlah fisik aktual', 'number', true), 'create_only' => true],
                [...$this->field('difference_quantity', 'Selisih', 'number'), 'editable' => false],
                [...$this->field('reason', 'Alasan', 'textarea', true), 'create_only' => true],
                [...$this->field('status', 'Status'), 'editable' => false],
                [...$this->field('verified_at', 'Diverifikasi', 'datetime'), 'editable' => false],
            ],
            'relations' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function warehouseWithdrawalDefinition(bool $nonFood = false): array
    {
        return [
            'label' => $nonFood ? 'Konfirmasi Pengambilan Non-Pangan' : 'Konfirmasi Pengambilan Pangan',
            'description' => 'Pastikan jenis dan jumlah barang yang telah diambil oleh petugas divisi.',
            'model' => WarehouseWithdrawal::class,
            'permission' => $nonFood ? 'non_food_stock' : 'stock',
            'warehouse_type' => $nonFood ? Warehouse::TYPE_NON_FOOD : Warehouse::TYPE_FOOD,
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
                    $this->field('photo_path', 'Foto pengambilan', 'file'),
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
                $this->field('photo_path', 'Foto bahan retur', 'file'),
                $this->field('warehouse_notes', 'Catatan Gudang'),
                $this->field('verified_at', 'Diverifikasi', 'datetime'),
            ],
            'relations' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function warehouseProcessingReturnDefinition(): array
    {
        return [
            'label' => 'Retur Pengolahan',
            'description' => 'Pemeriksaan bahan tidak terpakai dari Pengolahan dan keputusan saldo Gudang.',
            'model' => ProcessingReturn::class,
            'permission' => 'stock',
            'number' => 'return_number',
            'date' => 'return_date',
            'allow_create' => false,
            'allow_update' => false,
            'allow_delete' => false,
            'fields' => [
                $this->field('return_date', 'Tanggal retur', 'date'),
                $this->field('return_number', 'Nomor retur'),
                $this->field('ingredient_name_snapshot', 'Bahan'),
                $this->field('unit_snapshot', 'Satuan'),
                $this->field('requested_quantity', 'Diajukan', 'number'),
                $this->field('actual_quantity', 'Aktual', 'number'),
                $this->field('warehouse_disposition', 'Keputusan Gudang'),
                $this->field('status', 'Status'),
                $this->field('reason', 'Alasan'),
                $this->field('photo_path', 'Foto bahan retur', 'file'),
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
            [...$this->field('output_name', 'Nama hasil'), 'editable' => false],
            [...$this->field('source_ingredient_name_snapshot', 'Bahan asal'), 'editable' => false],
            $this->field('quantity', 'Jumlah awal', 'number', $viewer === 'preparation'),
            [...$this->field('available_quantity', 'Jumlah tersedia', 'number'), 'editable' => false],
            [...$this->field('unit_snapshot', 'Satuan'), 'editable' => false],
            $this->field('target_division', 'Tujuan penggunaan', 'select', $viewer === 'preparation', [
                'processing' => 'Pengolahan',
                'portioning' => 'Pemorsian',
                'both' => 'Pengolahan dan Pemorsian',
            ]),
            $this->field('storage_location', 'Lokasi setelah Persiapan', 'select', false, [
                'langsung_digunakan' => 'Langsung digunakan',
                'area_persiapan' => 'Area Persiapan',
                'chiller' => 'Chiller',
                'freezer' => 'Freezer',
            ]),
            [...$this->field('stored_at', 'Waktu disimpan', 'datetime'), 'editable' => false],
            $this->field('expires_at', 'Batas penggunaan', 'datetime'),
            [...$this->field('state', 'Status'), 'editable' => false],
            $this->field('photo_path', 'Dokumentasi', 'file'),
            $this->field('notes', 'Catatan'),
        ];

        if ($viewer === 'preparation') {
            array_unshift(
                $fields,
                [...$this->field('preparation_session_item_id', 'Pilih hasil bahan Persiapan', 'select', true, 'preparation_session_items'), 'create_only' => true],
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
        $isPreparation = $division === 'preparation';
        $permission = match ($division) {
            'washing' => 'washing',
            'cleaning' => 'cleaning',
            default => 'preparation',
        };

        return [
            'label' => $isPreparation ? 'BA Limbah Persiapan' : 'Berita Acara Limbah',
            'description' => $isPreparation
                ? 'Lengkapi pihak penyerah dan penerima pada berita acara yang dibuat otomatis dari Persiapan.'
                : 'Berita acara serah-terima limbah divisi yang terhubung ke pekerjaan sumber.',
            'model' => WasteHandoverReport::class,
            'permission' => $permission,
            'number' => 'report_number',
            'date' => 'report_date',
            'allow_create' => ! $isPreparation,
            'allow_delete' => ! $isPreparation,
            'where' => ['division_type' => $division],
            'fields' => [
                [...$this->field('report_date', 'Tanggal laporan', 'date', true), 'editable' => ! $isPreparation],
                $this->field('handed_over_at', 'Waktu serah-terima', 'datetime', true),
                [...$this->field('source_type', 'Jenis pekerjaan sumber', 'select', true, [
                    $division.'_session' => match ($division) {
                        'washing' => 'Sesi Pencucian',
                        'cleaning' => 'Sesi Kebersihan',
                        default => 'Sesi Persiapan',
                    },
                ]), 'editable' => ! $isPreparation],
                [...$this->field('source_id', 'Pekerjaan sumber', 'select', true, $division.'_sessions'), 'editable' => ! $isPreparation],
                [...$this->field('source_reference', 'Referensi pekerjaan'), 'editable' => ! $isPreparation],
                $this->field('first_party_name', 'Pihak pertama', 'text', true),
                $this->field('first_party_position', 'Jabatan pihak pertama', 'text', true),
                $this->field('first_party_address', 'Alamat pihak pertama', 'textarea', true),
                $this->field('second_party_name', 'Pihak kedua', 'text', true),
                $this->field('second_party_position', 'Jabatan pihak kedua', 'text', true),
                $this->field('second_party_address', 'Alamat pihak kedua', 'textarea', true),
                [...$this->field('status', 'Status'), 'editable' => false],
                $this->field('notes', 'Catatan'),
            ],
            'relations' => [
                'items' => $this->relation('Daftar limbah', [
                    [...$this->field('waste_type', 'Jenis limbah', 'text', true), 'editable' => ! $isPreparation],
                    [...$this->field('quantity', 'Jumlah', 'number', true), 'editable' => ! $isPreparation],
                    [...$this->field('unit', 'Satuan', 'text', true), 'editable' => ! $isPreparation],
                    [...$this->field('photo_path', 'Dokumentasi', 'file'), 'editable' => ! $isPreparation],
                    [...$this->field('notes', 'Catatan'), 'editable' => ! $isPreparation],
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
            'allow_delete' => false,
            'fields' => [
                $this->field('incident_date', 'Tanggal', 'date', true),
                $this->field('occurred_at', 'Waktu kejadian', 'datetime', true),
                $this->field('division_code', 'Divisi', 'select', true, [
                    'warehouse' => 'Gudang',
                    'preparation' => 'Persiapan',
                    'processing' => 'Pengolahan',
                    'portioning' => 'Pemorsian',
                    'distribution' => 'Distribusi',
                    'washing' => 'Pencucian',
                    'cleaning' => 'Kebersihan',
                    'security' => 'Keamanan',
                    'field' => 'Asisten Lapangan',
                ]),
                $this->field('category', 'Kategori', 'text', true),
                $this->field('severity', 'Tingkat', 'select', true, FieldIncidentSeverity::class),
                $this->field('title', 'Judul', 'text', true),
                $this->field('description', 'Deskripsi', 'textarea', true),
                $this->field('location', 'Lokasi', 'text', true),
                $this->field('responsible_name_snapshot', 'Penanggung jawab'),
                $this->field('due_at', 'Batas tindak lanjut', 'datetime'),
                [...$this->field('status', 'Status', 'select', false, FieldIncidentStatus::class), 'editable' => false],
                $this->field('immediate_action', 'Tindakan langsung', 'textarea'),
                $this->field('root_cause', 'Akar masalah', 'textarea'),
                $this->field('resolution', 'Penyelesaian', 'textarea'),
                $this->field('evidence_photo', 'Foto bukti kejadian', 'file'),
                [...$this->field('resolved_at', 'Diselesaikan', 'datetime'), 'editable' => false],
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
            'allow_create' => true,
            'allow_delete' => false,
            'fields' => [
                [...$this->field('report_date', 'Tanggal laporan', 'date', true), 'create_only' => true],
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
