<?php

namespace App\Http\Controllers\Api;

use App\Enums\DistributionIncidentStatus;
use App\Enums\FieldIncidentStatus;
use App\Enums\OperationalReportStatus;
use App\Http\Controllers\Controller;
use App\Models\CleaningSession;
use App\Models\ContainerCollectionRun;
use App\Models\ContainerCollectionTask;
use App\Models\DistributionRun;
use App\Models\DistributionStop;
use App\Models\FieldDistributionPlan;
use App\Models\InventoryLot;
use App\Models\PortioningSession;
use App\Models\PreparationOutputWithdrawal;
use App\Models\PreparationReturn;
use App\Models\PreparationSession;
use App\Models\PreparationSessionItem;
use App\Models\ProcessingBatch;
use App\Models\ProcessingReturn;
use App\Models\StockAdjustment;
use App\Models\StockReceipt;
use App\Models\Warehouse;
use App\Models\WarehouseWithdrawal;
use App\Models\WashingSession;
use App\Models\WasteHandoverReport;
use App\Services\CleaningScheduleService;
use App\Services\CleaningWorkflow;
use App\Services\ContainerCollectionWorkflow;
use App\Services\DistributionWorkflow;
use App\Services\FieldDailyReportGenerator;
use App\Services\FieldDailyReportWorkflow;
use App\Services\FieldOperationalPlanGenerator;
use App\Services\MobileDailyBeneficiaryConfirmationService;
use App\Services\OpeningStockService;
use App\Services\PortioningWorkflow;
use App\Services\PreparationOutputService;
use App\Services\PreparationReturnService;
use App\Services\PreparationSessionService;
use App\Services\PreparationWasteReportSyncService;
use App\Services\ProcessingPortioningHandoverService;
use App\Services\ProcessingReturnService;
use App\Services\ProcessingWorkflow;
use App\Services\SecurityMonitoringService;
use App\Services\StockControlService;
use App\Services\StockReceiptService;
use App\Services\V3\OperationalRecordInitializer;
use App\Services\WarehouseWithdrawalService;
use App\Services\WashingWorkflow;
use App\Services\WasteHandoverWorkflow;
use App\Support\DivisionRole;
use App\Support\Mobile\MobileOperationalRecordTransformer;
use App\Support\Mobile\MobileWorkspaceRegistry;
use App\Support\V3\SystemUnit;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MobileOperationalController extends Controller
{
    public function modules(
        Request $request,
        MobileWorkspaceRegistry $registry,
        SystemUnit $systemUnit,
        CleaningScheduleService $cleaningSchedule,
    ): JsonResponse {
        $today = now()->toDateString();
        $definitions = $registry->forUser($request->user());

        if (isset($definitions['kebersihan'])) {
            $cleaningSchedule->ensureForDate($systemUnit->id(), $today, $request->user());
        }

        $modules = collect($definitions)
            ->map(function (array $definition, string $slug) use ($request, $registry, $systemUnit, $today): array {
                $model = $definition['model'];
                $emptyRecord = new $model;

                $query = $model::query()->where('sppg_unit_id', $systemUnit->id());
                $this->applyDefinitionScope($query, $definition);
                $this->applyDistributionActorScope($query, $slug, $request->user());

                return [
                    'slug' => $slug,
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'permission' => $definition['permission'],
                    'record_count' => $query->count(),
                    'today_count' => (clone $query)->whereDate($definition['date'], $today)->count(),
                    'can_create' => ($definition['allow_create'] ?? true)
                        && $request->user()->can($definition['permission'].'.create'),
                    'form_fields' => $this->formFields(
                        $definition,
                        $emptyRecord,
                        $registry,
                        (int) $systemUnit->id(),
                    ),
                ];
            })->values();

        $plans = FieldDistributionPlan::query()
            ->where('sppg_unit_id', $systemUnit->id())
            ->whereDate('distribution_date', $today)
            ->where('status', '!=', 'cancelled')
            ->get();

        return response()->json([
            'data' => $modules,
            'daily_summary' => [
                'date' => $today,
                'menu_names' => $plans->pluck('menu_name_snapshot')->filter()->unique()->values(),
                'beneficiaries' => (int) $plans->sum(
                    fn (FieldDistributionPlan $plan): int => (int) ($plan->confirmed_beneficiaries ?: $plan->planned_beneficiaries),
                ),
                'portions' => (int) $plans->sum('planned_total_portions'),
                'destinations' => (int) $plans->sum('destination_count'),
            ],
        ]);
    }

    public function index(
        Request $request,
        string $module,
        MobileWorkspaceRegistry $registry,
        MobileOperationalRecordTransformer $transformer,
        SystemUnit $systemUnit,
        CleaningScheduleService $cleaningSchedule,
    ): JsonResponse {
        $definition = $registry->authorize($request->user(), $module);

        if ($module === 'kebersihan') {
            $cleaningSchedule->ensureForDate($systemUnit->id(), now()->toDateString(), $request->user());
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'view' => ['nullable', Rule::in(['active'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $includeCrossDayProcessing = $module === 'pengolahan'
            && blank($filters['view'] ?? null)
            && ($filters['date_from'] ?? null) === now()->toDateString()
            && ($filters['date_to'] ?? null) === now()->toDateString();
        $model = $definition['model'];
        $query = $model::query()
            ->where('sppg_unit_id', $systemUnit->id())
            ->when($filters['search'] ?? null, function (Builder $query, string $search) use ($definition, $module): void {
                $query->where(function (Builder $query) use ($definition, $module, $search): void {
                    $query->where($definition['number'], 'like', "%{$search}%");
                    if ($module === 'distribusi') {
                        $query->orWhere('route_name', 'like', "%{$search}%")
                            ->orWhere('menu_name_snapshot', 'like', "%{$search}%")
                            ->orWhere('driver_name', 'like', "%{$search}%");
                    }
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query
                ->where('status', $status))
            ->when($filters['date_from'] ?? null, function (Builder $query, string $date) use ($definition, $includeCrossDayProcessing): void {
                $includeCrossDayProcessing
                    ? $query->where(fn (Builder $dateQuery) => $dateQuery
                        ->whereDate($definition['date'], '>=', $date)
                        ->orWhere('state', 'in_progress'))
                    : $query->whereDate($definition['date'], '>=', $date);
            })
            ->when($filters['date_to'] ?? null, function (Builder $query, string $date) use ($definition, $includeCrossDayProcessing): void {
                $includeCrossDayProcessing
                    ? $query->where(fn (Builder $dateQuery) => $dateQuery
                        ->whereDate($definition['date'], '<=', $date)
                        ->orWhere('state', 'in_progress'))
                    : $query->whereDate($definition['date'], '<=', $date);
            });
        if ($module === 'pengolahan' && ($filters['view'] ?? null) === 'active') {
            $query->where(function (Builder $activeQuery) use ($definition): void {
                $activeQuery->where('state', 'in_progress')
                    ->orWhere(function (Builder $plannedQuery) use ($definition): void {
                        $plannedQuery->where('state', 'planned')
                            ->whereDate($definition['date'], now()->toDateString());
                    });
            });
        }
        $this->applyDefinitionScope($query, $definition);
        $this->applyDistributionActorScope($query, $module, $request->user());

        $this->addSummaryCounts($query, $module);
        if ($module === 'distribusi') {
            $query->orderByRaw(
                'CASE WHEN petugas_id = ? AND state IN (?, ?, ?, ?) THEN 0 WHEN state = ? THEN 1 ELSE 2 END',
                [
                    $request->user()->getKey(), 'assigned', 'loaded', 'departed',
                    'destinations_completed', 'planned',
                ],
            );
        }
        $records = $query->latest($definition['date'])->latest('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => collect($records->items())->map(
                fn ($record): array => $transformer->summary($module, $definition, $record),
            ),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function show(
        Request $request,
        string $module,
        int $record,
        MobileWorkspaceRegistry $registry,
        MobileOperationalRecordTransformer $transformer,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $definition = $registry->authorize($request->user(), $module);
        $model = $definition['model'];
        $relations = array_values(array_filter(
            array_keys($definition['relations'] ?? []),
            fn (string $relation): bool => method_exists($model, $relation),
        ));
        $query = $model::query()->where('sppg_unit_id', $systemUnit->id());
        $this->applyDefinitionScope($query, $definition);
        $this->applyDistributionActorScope($query, $module, $request->user());
        $this->addSummaryCounts($query, $module);
        $item = $query->with($relations)->findOrFail($record);

        $detail = $transformer->detail(
            $module,
            $definition,
            $item,
            (int) $systemUnit->id(),
        );
        $detail['sections'] = $this->enrichSections(
            $detail['sections'],
            $module,
            $definition,
            $item,
            $registry,
            (int) $systemUnit->id(),
            $this->isEditable($item) && $request->user()->can($definition['permission'].'.update'),
            $request,
        );

        return response()->json(['data' => [
            ...$detail,
            'form_fields' => $this->formFields($definition, $item, $registry, (int) $systemUnit->id()),
            'capabilities' => $this->capabilities($request, $definition, $item, $module),
        ]]);
    }

    public function store(
        Request $request,
        string $module,
        MobileWorkspaceRegistry $registry,
        MobileOperationalRecordTransformer $transformer,
        OperationalRecordInitializer $initializer,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $definition = $registry->authorize($request->user(), $module);
        abort_unless(($definition['allow_create'] ?? true), 422, 'Data modul ini dibuat otomatis oleh alur operasional.');
        abort_unless($request->user()->can($definition['permission'].'.create'), 403);
        $unitId = (int) $systemUnit->id();

        try {
            $item = DB::transaction(function () use (
                $request, $module, $definition, $initializer, $systemUnit, $unitId,
            ): Model {
                if ($module === 'lapangan-konfirmasi') {
                    $fields = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.service_date' => ['required', 'date'],
                        'fields.notes' => ['nullable', 'string', 'max:2000'],
                    ])['fields'];
                    $records = app(MobileDailyBeneficiaryConfirmationService::class)
                        ->generateForDate($unitId, (string) $fields['service_date'], $request->user());
                    $record = $records->first();
                    abort_unless($record instanceof Model, 422, 'Konfirmasi penerima tidak berhasil dibentuk.');
                    if (filled($fields['notes'] ?? null)) {
                        $records->each(fn (Model $confirmation) => $confirmation
                            ->forceFill(['notes' => trim((string) $fields['notes'])])
                            ->save());
                    }

                    return $record->refresh();
                }

                if (in_array($module, ['gudang-stok-awal', 'gudang-stok-awal-non-pangan'], true)) {
                    $nonFood = $module === 'gudang-stok-awal-non-pangan';
                    $input = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.opening_date' => ['required', 'date', 'before_or_equal:today'],
                        'fields.notes' => ['nullable', 'string', 'max:3000'],
                        'fields.rows_payload' => ['required', 'string', 'max:1000000'],
                        'files.photo_path' => ['required', 'string', 'max:7500000'],
                    ]);
                    $rows = json_decode((string) $input['fields']['rows_payload'], true);
                    if (! is_array($rows)) {
                        throw ValidationException::withMessages(['fields.rows_payload' => 'Daftar barang stok awal tidak valid.']);
                    }
                    $rows = validator(['rows' => $rows], [
                        'rows' => ['required', 'array', 'min:1', 'max:100'],
                        'rows.*.mode' => ['required', Rule::in(['existing', 'new'])],
                        'rows.*.ingredient_id' => ['nullable', 'integer'],
                        'rows.*.non_food_item_id' => ['nullable', 'integer'],
                        'rows.*.new_name' => ['nullable', 'string', 'max:255'],
                        'rows.*.new_category' => ['nullable', Rule::in([
                            'staple', 'animal_protein', 'plant_protein', 'vegetable', 'fruit', 'seasoning',
                            'oil', 'drink', 'dairy', 'processed', 'other',
                        ])],
                        'rows.*.measurement_unit_id' => ['nullable', 'integer'],
                        'rows.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
                        'rows.*.lot_number' => ['nullable', 'string', 'max:100'],
                        'rows.*.expired_date' => ['nullable', 'date'],
                        'rows.*.storage_type' => ['required', Rule::in(['dry', 'wet', 'freezer', 'chiller'])],
                        'rows.*.location_name' => ['nullable', 'string', 'max:255'],
                        'rows.*.condition_notes' => ['nullable', 'string', 'max:2000'],
                    ])->validate()['rows'];
                    foreach ($rows as $index => &$row) {
                        $row += [
                            'ingredient_id' => null, 'new_name' => null, 'new_category' => 'other',
                            'measurement_unit_id' => null, 'lot_number' => null, 'expired_date' => null,
                            'location_name' => null, 'condition_notes' => null,
                        ];
                        $selectedId = $nonFood ? ($row['non_food_item_id'] ?? null) : ($row['ingredient_id'] ?? null);
                        if ($row['mode'] === 'existing' && blank($selectedId)) {
                            throw ValidationException::withMessages(['fields.rows_payload' => 'Pilih barang pada baris '.($index + 1).'.']);
                        }
                        if ($nonFood && $row['mode'] === 'new') {
                            throw ValidationException::withMessages(['fields.rows_payload' => 'Master barang Non-Pangan baru dibuat melalui website. Pilih barang yang sudah tersedia.']);
                        }
                        if (! $nonFood && $row['mode'] === 'new' && (blank($row['new_name']) || blank($row['measurement_unit_id']))) {
                            throw ValidationException::withMessages(['fields.rows_payload' => 'Nama dan satuan barang baru pada baris '.($index + 1).' wajib diisi.']);
                        }
                    }
                    unset($row);
                    $photoPath = $this->storeEncodedImage(
                        (string) data_get($input, 'files.photo_path'),
                        'v3/warehouse/opening-stocks',
                        'files.photo_path',
                    );
                    try {
                        return app(OpeningStockService::class)->createForWarehouse(
                            $unitId,
                            (string) $input['fields']['opening_date'],
                            $photoPath,
                            $input['fields']['notes'] ?? null,
                            $rows,
                            $request->user(),
                            $nonFood ? Warehouse::TYPE_NON_FOOD : Warehouse::TYPE_FOOD,
                        );
                    } catch (\Throwable $exception) {
                        Storage::disk('public')->delete($photoPath);
                        throw $exception;
                    }
                }

                if (in_array($module, ['gudang', 'gudang-non-pangan'], true)) {
                    $nonFood = $module === 'gudang-non-pangan';
                    $input = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.receipt_date' => ['required', 'date', 'before_or_equal:today'],
                        'fields.supplier_id' => ['required', 'integer'],
                        'fields.manual_rows_payload' => ['required', 'string', 'max:1000000'],
                        'fields.notes' => ['nullable', 'string', 'max:3000'],
                    ])['fields'];
                    $rows = json_decode((string) $input['manual_rows_payload'], true);
                    if (! is_array($rows)) {
                        throw ValidationException::withMessages(['fields.manual_rows_payload' => 'Daftar barang penerimaan manual tidak valid.']);
                    }
                    $itemKey = $nonFood ? 'non_food_item_id' : 'ingredient_id';
                    $itemTable = $nonFood ? 'non_food_items' : 'ingredients';
                    $rows = validator(['rows' => $rows], [
                        'rows' => ['required', 'array', 'min:1', 'max:100'],
                        "rows.*.{$itemKey}" => ['required', 'integer', Rule::exists($itemTable, 'id')->where(fn ($query) => $query
                            ->where('sppg_unit_id', $unitId)->where('is_active', true))],
                        'rows.*.received_quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
                        'rows.*.accepted_quantity' => ['required', 'numeric', 'min:0', 'max:9999999999'],
                        'rows.*.rejected_quantity' => ['required', 'numeric', 'min:0', 'max:9999999999'],
                        'rows.*.supplier_batch_number' => ['nullable', 'string', 'max:100'],
                        'rows.*.expired_date' => ['nullable', 'date'],
                        'rows.*.received_temperature_celsius' => ['nullable', 'numeric', 'between:-50,100'],
                        'rows.*.quality_notes' => ['nullable', 'string', 'max:1000'],
                    ])->validate()['rows'];
                    foreach ($rows as $index => $row) {
                        if (abs(((float) $row['accepted_quantity'] + (float) $row['rejected_quantity']) - (float) $row['received_quantity']) > 0.0001) {
                            throw ValidationException::withMessages([
                                'fields.manual_rows_payload' => 'Barang '.($index + 1).': jumlah baik + ditolak harus sama dengan jumlah diterima.',
                            ]);
                        }
                    }
                    $warehouse = Warehouse::forUnit(
                        $unitId,
                        $nonFood ? Warehouse::TYPE_NON_FOOD : Warehouse::TYPE_FOOD,
                    );

                    return app(StockReceiptService::class)->createManual(
                        $unitId,
                        $warehouse->getKey(),
                        (int) $input['supplier_id'],
                        (string) $input['receipt_date'],
                        $input['notes'] ?? null,
                        $rows,
                        $request->user(),
                    );
                }

                if (in_array($module, ['gudang-penyesuaian', 'gudang-penyesuaian-non-pangan'], true)) {
                    $fields = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.inventory_lot_id' => ['required', 'integer'],
                        'fields.actual_quantity' => ['required', 'numeric', 'min:0'],
                        'fields.type' => ['required', Rule::in(['stock_opname', 'return_from_division', 'damage'])],
                        'fields.reason' => ['required', 'string', 'max:2000'],
                    ])['fields'];
                    $lot = InventoryLot::query()
                        ->where('sppg_unit_id', $unitId)
                        ->where(function (Builder $query) use ($module): void {
                            $type = $module === 'gudang-penyesuaian-non-pangan'
                                ? Warehouse::TYPE_NON_FOOD
                                : Warehouse::TYPE_FOOD;
                            $query->whereHas('warehouse', fn (Builder $warehouseQuery) => $warehouseQuery->where('type', $type));
                            if ($type === Warehouse::TYPE_FOOD) {
                                $query->orWhereNull('warehouse_id');
                            }
                        })
                        ->findOrFail((int) $fields['inventory_lot_id']);

                    return app(StockControlService::class)->create(
                        $lot,
                        (float) $fields['actual_quantity'],
                        (string) $fields['type'],
                        (string) $fields['reason'],
                        $request->user(),
                    );
                }

                if (in_array($module, [
                    'pengambilan-gudang-persiapan',
                    'pengambilan-gudang-pengolahan',
                    'pengambilan-gudang-pemorsian',
                ], true)) {
                    $fields = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.reference_selection' => ['required', 'string', 'max:80'],
                        'fields.purpose_reference' => ['nullable', 'string', 'max:255'],
                        'fields.shift' => ['nullable', 'string', 'max:100'],
                        'fields.notes' => ['nullable', 'string', 'max:2000'],
                    ])['fields'];
                    $division = str_replace('pengambilan-gudang-', '', $module);

                    return app(WarehouseWithdrawalService::class)->createMobileDraft(
                        $unitId,
                        $division,
                        (string) $fields['reference_selection'],
                        $fields['purpose_reference'] ?? null,
                        $fields['shift'] ?? null,
                        $fields['notes'] ?? null,
                        $request->user(),
                    );
                }

                if ($module === 'pengambilan-non-pangan') {
                    $fields = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.purpose_reference' => ['required', 'string', 'max:255'],
                        'fields.notes' => ['nullable', 'string', 'max:2000'],
                    ])['fields'];
                    $division = $request->user()->getRoleNames()
                        ->map(fn (string $role): ?string => DivisionRole::divisionCodeForRole($role))
                        ->filter()
                        ->first();
                    abort_unless(is_string($division), 403, 'Akun tidak terhubung ke salah satu divisi operasional.');

                    return app(WarehouseWithdrawalService::class)->createNonFoodDraft(
                        $unitId,
                        $division,
                        (string) $fields['purpose_reference'],
                        $fields['notes'] ?? null,
                        $request->user(),
                    );
                }

                if ($module === 'lapangan-laporan') {
                    $fields = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.report_date' => ['required', 'date'],
                    ])['fields'];

                    return app(FieldDailyReportGenerator::class)->generate(
                        $unitId,
                        (string) $fields['report_date'],
                        $request->user(),
                    );
                }

                if ($module === 'keamanan') {
                    return app(SecurityMonitoringService::class)
                        ->startShift($systemUnit->get(), $request->user())
                        ->refresh();
                }

                if ($module === 'pengolahan') {
                    $fields = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.production_date' => ['required', 'date'],
                        'fields.product_name' => ['required', 'string', 'max:255'],
                    ])['fields'];
                    $batch = ProcessingBatch::create([
                        'sppg_unit_id' => $unitId,
                        'production_date' => $fields['production_date'],
                        'service_date' => $fields['production_date'],
                        'product_name' => trim($fields['product_name']),
                        'menu_name_snapshot' => trim($fields['product_name']),
                        'target_output_quantity' => 0, 'target_output_unit' => 'porsi',
                        'created_by' => $request->user()->getKey(), 'updated_by' => $request->user()->getKey(),
                    ]);
                    return app(ProcessingWorkflow::class)->start($batch, $request->user());
                }

                if ($module === 'pemorsian') {
                    $fields = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.field_distribution_plan_id' => ['required', 'integer'],
                    ])['fields'];
                    $plan = FieldDistributionPlan::query()
                        ->where('sppg_unit_id', $unitId)
                        ->where('status', 'activated')
                        ->findOrFail((int) $fields['field_distribution_plan_id']);
                    $session = app(FieldOperationalPlanGenerator::class)
                        ->generatePortioningSession($plan, $request->user());

                    if ($session->state->value === 'planned') {
                        return app(PortioningWorkflow::class)->start($session, $request->user());
                    }
                    if ($session->state->value !== 'in_progress') {
                        throw ValidationException::withMessages([
                            'fields.field_distribution_plan_id' => 'Rencana ini sudah memiliki sesi Pemorsian yang tidak dapat dimulai kembali.',
                        ]);
                    }

                    return $session;
                }

                if ($module === 'pengambilan-ompreng') {
                    $fields = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.kernet_name' => ['nullable', 'string', 'max:150'],
                        'fields.vehicle_name' => ['nullable', 'string', 'max:150'],
                        'fields.vehicle_plate' => ['nullable', 'string', 'max:30'],
                        'fields.notes' => ['nullable', 'string', 'max:2000'],
                    ])['fields'];

                    return app(ContainerCollectionWorkflow::class)
                        ->startRun($unitId, $request->user(), $fields);
                }

                if ($module === 'hasil-persiapan') {
                    $input = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.preparation_session_item_id' => ['required', 'integer'],
                        'fields.quantity' => ['required', 'numeric', 'gt:0'],
                        'fields.target_division' => ['required', Rule::in(['processing', 'portioning', 'both'])],
                        'fields.storage_location' => ['nullable', Rule::in(['langsung_digunakan', 'area_persiapan', 'chiller', 'freezer'])],
                        'fields.expires_at' => ['nullable', 'date', 'after:now'],
                        'fields.notes' => ['nullable', 'string', 'max:2000'],
                        'files.photo_path' => ['nullable', 'string', 'max:7500000'],
                    ]);
                    $sourceItem = PreparationSessionItem::query()
                        ->whereHas('session', fn (Builder $query) => $query->where('sppg_unit_id', $unitId))
                        ->findOrFail((int) $input['fields']['preparation_session_item_id']);
                    $session = $sourceItem->session()->firstOrFail();
                    $photoPath = null;
                    if (filled(data_get($input, 'files.photo_path'))) {
                        $photoPath = $this->storeEncodedImage(
                            (string) data_get($input, 'files.photo_path'),
                            'mobile/hasil-persiapan/records',
                            'files.photo_path',
                        );
                    }
                    try {
                        return app(PreparationOutputService::class)->store(
                            $session,
                            $sourceItem,
                            $request->user(),
                            [
                                ...$input['fields'],
                                'output_name' => $sourceItem->ingredient_name_snapshot,
                                'unit_snapshot' => $sourceItem->unit_snapshot,
                                'stored_at' => now(),
                                'photo_path' => $photoPath,
                            ],
                        );
                    } catch (\Throwable $exception) {
                        if ($photoPath) {
                            Storage::disk('public')->delete($photoPath);
                        }
                        throw $exception;
                    }
                }

                $model = $definition['model'];
                $item = new $model;
                $values = $this->validatedValues($request, $definition);
                if (str_starts_with($module, 'ba-limbah-')) {
                    $values = app(WasteHandoverWorkflow::class)
                        ->normalizeAndValidateSource($values, $unitId);
                }
                $item->fill($values);
                foreach ($definition['where'] ?? [] as $field => $value) {
                    $item->setAttribute($field, $value);
                }
                $this->applySystemValues($item, $request, $unitId, creating: true);
                $item->save();
                $this->storeRecordFiles($request, $module, $item, $definition);
                $initializer->initialize($item->refresh(), $request->user());
                if (str_starts_with($module, 'ba-limbah-')) {
                    $this->linkWasteHandoverSource($item);
                }

                return $item->refresh();
            });
        } catch (DomainException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'fields' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $definition['label'].' berhasil dibuat.',
            'data' => $transformer->detail(
                $module,
                $definition,
                $this->loadRelations($item, $definition),
                $unitId,
            ),
        ], 201);
    }

    public function update(
        Request $request,
        string $module,
        int $record,
        MobileWorkspaceRegistry $registry,
        MobileOperationalRecordTransformer $transformer,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $definition = $registry->authorize($request->user(), $module);
        abort_unless(($definition['allow_update'] ?? true), 422, 'Data modul ini hanya dapat diubah melalui alur kerja.');
        abort_unless($request->user()->can($definition['permission'].'.update'), 403);
        $item = $this->findRecord($definition, $record, (int) $systemUnit->id());
        $this->assertDistributionRecordAccess($module, $item, $request->user());
        abort_unless($this->isEditable($item), 422, 'Data sudah dikunci dan tidak dapat diubah.');

        DB::transaction(function () use ($request, $module, $definition, $item, $systemUnit): void {
            if ($module === 'lapangan-konfirmasi') {
                $fields = $request->validate([
                    'fields' => ['present', 'array'],
                    'fields.notes' => ['nullable', 'string', 'max:2000'],
                ])['fields'];
                $item->forceFill([
                    'notes' => filled($fields['notes'] ?? null) ? trim((string) $fields['notes']) : null,
                ])->save();

                return;
            }

            if (in_array($module, [
                'pengambilan-gudang-persiapan',
                'pengambilan-gudang-pengolahan',
                'pengambilan-gudang-pemorsian',
            ], true)) {
                abort_unless((int) $item->taken_by === (int) $request->user()->getKey(), 403);
                $fields = $request->validate([
                    'fields' => ['present', 'array'],
                    'fields.purpose_reference' => ['nullable', 'string', 'max:255'],
                    'fields.shift' => ['nullable', 'string', 'max:100'],
                    'fields.notes' => ['nullable', 'string', 'max:2000'],
                ])['fields'];
                $item->forceFill([
                    'purpose_reference' => filled($fields['purpose_reference'] ?? null)
                        ? trim((string) $fields['purpose_reference'])
                        : $item->reference_number_snapshot,
                    'shift' => filled($fields['shift'] ?? null) ? trim((string) $fields['shift']) : null,
                    'notes' => filled($fields['notes'] ?? null) ? trim((string) $fields['notes']) : null,
                ])->save();

                return;
            }

            $values = $this->validatedValues($request, $definition);
            if ($module === 'lapangan-laporan') {
                app(FieldDailyReportWorkflow::class)->update(
                    $item,
                    $request->user(),
                    $values,
                );
            } else {
                if (str_starts_with($module, 'ba-limbah-')) {
                    $this->unlinkWasteHandoverSource($item);
                    $values = app(WasteHandoverWorkflow::class)
                        ->normalizeAndValidateSource(
                            $values + $item->only(['source_type', 'source_id']),
                            (int) $systemUnit->id(),
                            (int) $item->getKey(),
                        );
                }
                $item->fill($values);
                $this->applySystemValues($item, $request, (int) $systemUnit->id());
                $item->save();
                if (str_starts_with($module, 'ba-limbah-')) {
                    $this->linkWasteHandoverSource($item);
                }
            }
            $this->storeRecordFiles($request, $module, $item, $definition);
        });

        return response()->json([
            'message' => 'Perubahan berhasil disimpan.',
            'data' => $transformer->detail(
                $module,
                $definition,
                $this->loadRelations($item->refresh(), $definition),
                (int) $systemUnit->id(),
            ),
        ]);
    }

    public function destroy(
        Request $request,
        string $module,
        int $record,
        MobileWorkspaceRegistry $registry,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $definition = $registry->authorize($request->user(), $module);
        abort_unless(($definition['allow_delete'] ?? true), 422, 'Data modul ini tidak boleh dihapus dari aplikasi.');
        abort_unless($request->user()->can($definition['permission'].'.delete'), 403);
        $item = $this->findRecord($definition, $record, (int) $systemUnit->id());
        abort_unless($this->isDeletable($item), 422, 'Data yang sudah mulai dikerjakan tidak dapat dihapus.');
        if (str_starts_with($module, 'pengambilan-gudang-')) {
            abort_unless((int) $item->taken_by === (int) $request->user()->getKey(), 403);
            $item->load('items');
            foreach ($item->items as $withdrawalItem) {
                if (filled($withdrawalItem->photo_path)) {
                    Storage::disk('public')->delete($withdrawalItem->photo_path);
                }
            }
        }
        if (str_starts_with($module, 'ba-limbah-')) {
            $this->unlinkWasteHandoverSource($item);
        }
        $item->delete();

        return response()->json(['message' => $definition['label'].' berhasil dihapus.']);
    }

    public function action(
        Request $request,
        string $module,
        int $record,
        string $action,
        MobileWorkspaceRegistry $registry,
        MobileOperationalRecordTransformer $transformer,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $definition = $registry->authorize($request->user(), $module);
        $item = $this->findRecord($definition, $record, (int) $systemUnit->id());
        $this->assertDistributionRecordAccess($module, $item, $request->user());
        $input = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'fields' => ['nullable', 'array'],
            'files' => ['nullable', 'array'],
            'files.*' => ['nullable', 'string', 'max:7500000'],
        ]);
        $notes = $input['notes'] ?? null;
        $fields = $input['fields'] ?? [];
        $files = $input['files'] ?? [];
        $available = collect($this->availableActions($request, $module, $item))->pluck('key');
        abort_unless($available->contains($action), 422, 'Tindakan tidak tersedia pada tahap pekerjaan ini.');

        $storedPhoto = null;
        if (filled($files['photo_path'] ?? null)) {
            $storedPhoto = $this->storeEncodedImage(
                (string) $files['photo_path'],
                "mobile/{$module}/actions",
                'files.photo_path',
            );
        }

        try {
            $item = DB::transaction(function () use (
                $module, $action, $item, $request, $notes, $fields, $storedPhoto,
            ): Model {
                $actor = $request->user();
                $data = [...$item->getAttributes(), ...$fields];

                return match ($module) {
                    'gudang', 'gudang-non-pangan' => $this->runWarehouseReceiptAction($action, $item, $actor),
                    'gudang-stok', 'gudang-stok-non-pangan' => $this->runWarehouseStockAction($action, $item, $actor, $fields, $notes),
                    'gudang-penyesuaian', 'gudang-penyesuaian-non-pangan' => $this->runWarehouseAdjustmentAction($action, $item, $actor),
                    'gudang-pengambilan', 'gudang-pengambilan-non-pangan' => $this->runWarehouseWithdrawalAction($action, $item, $actor, $notes),
                    'pengambilan-gudang-persiapan', 'pengambilan-gudang-pengolahan', 'pengambilan-gudang-pemorsian', 'pengambilan-non-pangan' => $this->runDivisionWarehouseWithdrawalAction($action, $item, $actor),
                    'gudang-retur' => $this->runPreparationReturnAction($action, $item, $actor, $fields, $notes),
                    'gudang-retur-pengolahan' => $this->runProcessingReturnAction($action, $item, $actor, $fields, $notes),
                    'lapangan-konfirmasi' => $this->runDailyBeneficiaryConfirmationAction($action, $item, $actor),
                    'persiapan' => $this->runPreparationAction($action, $item, $actor, $fields, $notes),
                    'hasil-persiapan', 'hasil-persiapan-pengolahan', 'hasil-persiapan-pemorsian' => $this->runPreparationOutputAction($module, $action, $item, $actor, $fields, $notes),
                    'pengolahan' => $action === 'complete'
                        ? app(ProcessingPortioningHandoverService::class)->completeAndHandover($item, $actor)
                        : ($action === 'receive_preparation_output'
                            ? $this->runPreparationOutputReceive($item, $actor, (int) ($fields['withdrawal_id'] ?? 0))
                            : $this->runStandardAction(app(ProcessingWorkflow::class), $action, $item, $actor, $notes)),
                    'pemorsian' => $action === 'receive_processing_batch'
                        ? $this->runPortioningReceiveBatch($item, $actor, (int) ($fields['processing_batch_id'] ?? 0))
                        : ($action === 'receive_preparation_output'
                            ? $this->runPreparationOutputReceive($item, $actor, (int) ($fields['withdrawal_id'] ?? 0))
                            : $this->runPortioningAction($action, $item, $actor, $notes)),
                    'distribusi' => $this->runDistributionAction($action, $item, $actor, $data, $notes),
                    'pengambilan-ompreng' => $this->runContainerCollectionAction(
                        $action, $item, $actor, $fields, $notes, $storedPhoto,
                    ),
                    'pencucian' => $this->runWashingAction($action, $item, $actor, $data, $notes),
                    'kebersihan' => $this->runCleaningAction($action, $item, $actor, $data, $notes),
                    'ba-limbah-persiapan', 'ba-limbah-pencucian', 'ba-limbah-kebersihan' => $this->runWasteHandoverAction($action, $item, $actor, $notes),
                    'lapangan-laporan' => $this->runFieldDailyReportAction($action, $item, $actor, $notes),
                    'lapangan-insiden' => $this->runFieldIncidentAction($action, $item, $actor, $fields, $notes),
                    default => abort(422, 'Tindakan belum tersedia untuk modul ini.'),
                };
            })->refresh();
        } catch (\Throwable $exception) {
            if ($storedPhoto) {
                Storage::disk('public')->delete($storedPhoto);
            }
            throw $exception;
        }

        return response()->json([
            'message' => 'Tahap pekerjaan berhasil diperbarui.',
            'data' => [
                ...$this->detailWithRelations(
                    $request,
                    $module,
                    $definition,
                    $this->loadRelations($item, $definition),
                    $registry,
                    $transformer,
                    (int) $systemUnit->id(),
                ),
                'capabilities' => $this->capabilities($request, $definition, $item, $module),
            ],
        ]);
    }

    public function storeRelation(
        Request $request,
        string $module,
        int $record,
        string $relation,
        MobileWorkspaceRegistry $registry,
        MobileOperationalRecordTransformer $transformer,
        SystemUnit $systemUnit,
    ): JsonResponse {
        [$definition, $parent, $relationDefinition] = $this->relationContext(
            $request, $module, $record, $relation, $registry, $systemUnit, 'create',
        );
        if (in_array($module, ['gudang', 'gudang-non-pangan'], true) && $relation === 'itemPhotos') {
            $input = $request->validate([
                'fields' => ['present', 'array'],
                'fields.stock_receipt_item_id' => ['required', 'integer'],
                'files.photo_path' => ['required', 'string', 'max:7500000'],
            ]);
            $receiptItem = $parent->items()->findOrFail((int) $input['fields']['stock_receipt_item_id']);
            $photoPath = $this->storeEncodedImage(
                (string) data_get($input, 'files.photo_path'),
                'mobile/stock-receipts/items/'.$receiptItem->getKey(),
                'files.photo_path',
            );
            try {
                $photo = $parent->itemPhotos()->create([
                    'stock_receipt_item_id' => $receiptItem->getKey(),
                    'item_name_snapshot' => $receiptItem->ingredient_name_snapshot,
                    'photo_path' => $photoPath,
                    'original_name' => basename($photoPath),
                    'uploaded_by' => $request->user()->getKey(),
                ]);
            } catch (\Throwable $exception) {
                Storage::disk('public')->delete($photoPath);
                throw $exception;
            }

            return response()->json([
                'message' => 'Foto barang berhasil ditambahkan. Anda dapat menambahkan foto berikutnya.',
                'data' => $this->relationItemData($photo, $relationDefinition, $registry, (int) $systemUnit->id()),
            ], 201);
        }
        if ($module === 'persiapan' && $relation === 'returns') {
            $input = $request->validate([
                'fields' => ['present', 'array'],
                'fields.preparation_session_item_id' => ['required', 'integer'],
                'fields.requested_quantity' => ['required', 'numeric', 'gt:0'],
                'fields.condition_status' => ['required', Rule::in(['good', 'damaged', 'rejected'])],
                'fields.reason' => ['required', 'string', 'max:1000'],
                'files.photo_path' => ['nullable', 'string', 'max:7500000'],
            ]);
            $sourceItem = $parent->items()->findOrFail((int) $input['fields']['preparation_session_item_id']);
            $photoPath = null;
            if (filled(data_get($input, 'files.photo_path'))) {
                $photoPath = $this->storeEncodedImage(
                    (string) data_get($input, 'files.photo_path'),
                    'mobile/persiapan/returns',
                    'files.photo_path',
                );
            }
            try {
                $return = app(PreparationReturnService::class)->submit(
                    $parent,
                    $sourceItem,
                    (float) $input['fields']['requested_quantity'],
                    (string) $input['fields']['condition_status'],
                    (string) $input['fields']['reason'],
                    $photoPath,
                    $request->user(),
                );
            } catch (\Throwable $exception) {
                if ($photoPath) {
                    Storage::disk('public')->delete($photoPath);
                }
                throw $exception;
            }

            return response()->json([
                'message' => 'Retur berhasil diajukan dan menunggu verifikasi Gudang.',
                'data' => $this->relationItemData($return, $relationDefinition, $registry, (int) $systemUnit->id()),
            ], 201);
        }
        if ($module === 'pengolahan' && $relation === 'returns') {
            $input = $request->validate([
                'fields' => ['present', 'array'],
                'fields.processing_material_usage_id' => ['required', 'integer'],
                'fields.requested_quantity' => ['required', 'numeric', 'gt:0'],
                'fields.reason' => ['required', 'string', 'max:1000'],
                'files.photo_path' => ['nullable', 'string', 'max:7500000'],
            ]);
            $usage = $parent->materialUsages()->findOrFail((int) $input['fields']['processing_material_usage_id']);
            $photoPath = filled(data_get($input, 'files.photo_path'))
                ? $this->storeEncodedImage(
                    (string) data_get($input, 'files.photo_path'),
                    'mobile/pengolahan/returns',
                    'files.photo_path',
                )
                : null;
            try {
                $return = app(ProcessingReturnService::class)->submit(
                    $parent,
                    $usage,
                    (float) $input['fields']['requested_quantity'],
                    (string) $input['fields']['reason'],
                    $request->user(),
                );
                if ($photoPath) {
                    $return->update(['photo_path' => $photoPath]);
                }
            } catch (\Throwable $exception) {
                if ($photoPath) {
                    Storage::disk('public')->delete($photoPath);
                }
                throw $exception;
            }

            return response()->json([
                'message' => 'Retur Pengolahan berhasil diajukan ke Gudang.',
                'data' => $this->relationItemData($return->refresh(), $relationDefinition, $registry, (int) $systemUnit->id()),
            ], 201);
        }

        if ($module === 'keamanan' && $relation === 'reports') {
            return $this->storeSecurityReport(
                $request,
                $parent,
                $relationDefinition,
                $registry,
                (int) $systemUnit->id(),
            );
        }
        if ((str_starts_with($module, 'pengambilan-gudang-') || $module === 'pengambilan-non-pangan') && $relation === 'items') {
            $input = $request->validate([
                'fields' => ['present', 'array'],
                'fields.inventory_lot_id' => ['required', 'integer'],
                'fields.requested_quantity' => ['required', 'numeric', 'gt:0'],
                'fields.pickup_temperature_celsius' => ['nullable', 'numeric'],
                'fields.notes' => ['nullable', 'string', 'max:2000'],
                'files.photo_path' => ['required', 'string', 'max:7500000'],
            ]);
            $photoPath = $this->storeEncodedImage(
                (string) data_get($input, 'files.photo_path'),
                "mobile/{$module}/items",
                'files.photo_path',
            );
            try {
                $service = app(WarehouseWithdrawalService::class);
                $createdItem = $module === 'pengambilan-non-pangan'
                    ? $service->addNonFoodItem(
                        $parent,
                        (int) $input['fields']['inventory_lot_id'],
                        (float) $input['fields']['requested_quantity'],
                        $photoPath,
                        $input['fields']['notes'] ?? null,
                        $request->user(),
                    )
                    : $service->createMobileDraftItem(
                        $parent,
                        (int) $input['fields']['inventory_lot_id'],
                        (float) $input['fields']['requested_quantity'],
                        filled($input['fields']['pickup_temperature_celsius'] ?? null)
                            ? (float) $input['fields']['pickup_temperature_celsius']
                            : null,
                        $photoPath,
                        $input['fields']['notes'] ?? null,
                        $request->user(),
                    );
            } catch (\Throwable $exception) {
                Storage::disk('public')->delete($photoPath);
                throw $exception;
            }

            return response()->json([
                'message' => 'Barang pengambilan berhasil ditambahkan.',
                'data' => $this->relationItemData(
                    $createdItem,
                    $relationDefinition,
                    $registry,
                    (int) $systemUnit->id(),
                ),
            ], 201);
        }
        if ($module === 'persiapan' && $relation === 'resultDocumentation') {
            $input = $request->validate([
                'fields' => ['present', 'array'],
                'files.photo_path' => ['required', 'string', 'max:7500000'],
            ]);
            $photoPath = $this->storeEncodedImage(
                (string) data_get($input, 'files.photo_path'),
                'mobile/persiapan/result-documentation',
                'files.photo_path',
            );
            $oldPhotoPath = $parent->resultDocumentation?->photo_path;
            try {
                $documentation = DB::transaction(fn () => $parent->resultDocumentation()->updateOrCreate([], [
                    'photo_path' => $photoPath,
                    'captured_at' => now(),
                    'created_by' => $request->user()->getKey(),
                ]));
            } catch (\Throwable $exception) {
                Storage::disk('public')->delete($photoPath);
                throw $exception;
            }
            if ($oldPhotoPath && $oldPhotoPath !== $photoPath) {
                Storage::disk('public')->delete($oldPhotoPath);
            }

            return response()->json([
                'message' => 'Foto hasil Persiapan berhasil disimpan.',
                'data' => $this->relationItemData(
                    $documentation->refresh(),
                    $relationDefinition,
                    $registry,
                    (int) $systemUnit->id(),
                ),
            ], 201);
        }
        $relationObject = $parent->{$relation}();
        $item = DB::transaction(function () use ($request, $module, $relation, $parent, $relationObject, $relationDefinition): Model {
            $values = $this->validatedRelationValues($request, $relationDefinition, null);
            $values = $this->applyRelationDefaults($module, $relation, $values, $request);
            $item = $relationObject instanceof HasOne
                ? $relationObject->updateOrCreate([], $values)
                : $relationObject->create($values);
            $this->storeRelationFiles($request, $module, $relation, $item, $relationDefinition);
            $this->recalculateParent($parent);

            return $item->refresh();
        });

        return response()->json([
            'message' => 'Rincian berhasil ditambahkan.',
            'data' => $this->relationItemData($item, $relationDefinition, $registry, (int) $systemUnit->id()),
        ], 201);
    }

    public function updateRelation(
        Request $request,
        string $module,
        int $record,
        string $relation,
        int $item,
        MobileWorkspaceRegistry $registry,
        SystemUnit $systemUnit,
    ): JsonResponse {
        [, $parent, $relationDefinition] = $this->relationContext(
            $request, $module, $record, $relation, $registry, $systemUnit, 'update',
        );
        $child = $parent->{$relation}()->whereKey($item)->firstOrFail();
        if ($module === 'pengolahan' && $relation === 'materialUsages') {
            abort_unless($child->source_type === 'manual', 422, 'Bahan yang berasal dari integrasi Gudang tidak dapat diubah dari catatan aktual.');
        }

        if (in_array($module, ['gudang', 'gudang-non-pangan'], true) && $relation === 'items') {
            $fields = $request->validate([
                'fields' => ['present', 'array'],
                'fields.received_quantity' => ['required', 'numeric', 'min:0'],
                'fields.accepted_quantity' => ['required', 'numeric', 'min:0'],
                'fields.rejected_quantity' => ['required', 'numeric', 'min:0'],
                'fields.supplier_batch_number' => ['nullable', 'string', 'max:100'],
                'fields.expired_date' => ['nullable', 'date'],
                'fields.received_temperature_celsius' => ['nullable', 'numeric'],
                'fields.quality_notes' => ['nullable', 'string', 'max:1000'],
            ])['fields'];
            $child = app(StockReceiptService::class)->updateInspection($child, $fields);

            return response()->json([
                'message' => 'Penerimaan dan hasil QC berhasil diperbarui.',
                'data' => $this->relationItemData($child->refresh(), $relationDefinition, $registry, (int) $systemUnit->id()),
            ]);
        }

        if ($module === 'distribusi'
            && $relation === 'stops'
            && $this->scalarValue($parent->getAttribute('state')) === 'returned'
            && $this->scalarValue($parent->getAttribute('status')) === 'revision_required') {
            $revisionDefinition = $relationDefinition;
            $revisionDefinition['fields'] = collect($revisionDefinition['fields'])
                ->map(function (array $field): array {
                    if ($field['name'] === 'status') {
                        $field['editable'] = true;
                        $field['options'] = [
                            'delivered' => 'Selesai',
                            'partial' => 'Terkirim Sebagian',
                            'failed' => 'Gagal Dikirim',
                        ];
                    }

                    return $field;
                })->all();
            $values = $this->validatedRelationValues($request, $revisionDefinition, $child);
            $oldPhoto = $child->handover_photo_path;
            $newPhoto = null;
            if (filled($request->input('files.handover_photo_path'))) {
                $newPhoto = $this->storeEncodedImage(
                    (string) $request->input('files.handover_photo_path'),
                    'mobile/distribusi/stops',
                    'files.handover_photo_path',
                );
                $values['handover_photo_path'] = $newPhoto;
            }

            try {
                $child = app(DistributionWorkflow::class)->reviseStop(
                    $parent,
                    $child,
                    $request->user(),
                    [...$child->getAttributes(), ...$values],
                );
            } catch (\Throwable $exception) {
                if ($newPhoto) {
                    Storage::disk('public')->delete($newPhoto);
                }
                throw $exception;
            }
            if ($newPhoto && $oldPhoto && $newPhoto !== $oldPhoto) {
                Storage::disk('public')->delete($oldPhoto);
            }

            return response()->json([
                'message' => 'Koreksi tujuan distribusi berhasil disimpan.',
                'data' => $this->relationItemData($child->refresh(), $revisionDefinition, $registry, (int) $systemUnit->id()),
            ]);
        }

        DB::transaction(function () use ($request, $module, $relation, $parent, $relationDefinition, $child): void {
            $values = $this->validatedRelationValues($request, $relationDefinition, $child);
            $child->fill($this->applyRelationDefaults($module, $relation, $values, $request));
            $child->save();
            $this->storeRelationFiles($request, $module, $relation, $child, $relationDefinition);
            $this->recalculateParent($parent);
        });

        if ($module === 'persiapan' && $relation === 'items') {
            $parent->refresh()->load('items');
            app(PreparationOutputService::class)->syncSessionOutputs(
                $parent,
                $request->user(),
                $parent->items->mapWithKeys(fn ($row): array => [$row->getKey() => $row->output_target_division ?: 'processing'])->all(),
            );
            app(PreparationWasteReportSyncService::class)->sync($parent, $request->user());
        }

        return response()->json([
            'message' => 'Rincian berhasil diperbarui.',
            'data' => $this->relationItemData($child->refresh(), $relationDefinition, $registry, (int) $systemUnit->id()),
        ]);
    }

    public function destroyRelation(
        Request $request,
        string $module,
        int $record,
        string $relation,
        int $item,
        MobileWorkspaceRegistry $registry,
        SystemUnit $systemUnit,
    ): JsonResponse {
        [, $parent, $relationDefinition] = $this->relationContext(
            $request, $module, $record, $relation, $registry, $systemUnit, 'delete',
        );
        $child = $parent->{$relation}()->whereKey($item)->firstOrFail();
        if ($module === 'pengolahan' && $relation === 'materialUsages') {
            abort_unless($child->source_type === 'manual', 422, 'Bahan yang berasal dari integrasi Gudang tidak dapat dihapus dari catatan aktual.');
        }
        DB::transaction(function () use ($parent, $relationDefinition, $child): void {
            foreach ($relationDefinition['fields'] as $field) {
                if (($field['type'] ?? null) === 'file' && filled($child->getAttribute($field['name']))) {
                    Storage::disk('public')->delete($child->getAttribute($field['name']));
                }
            }
            $child->delete();
            $this->recalculateParent($parent);
        });

        return response()->json(['message' => 'Rincian berhasil dihapus.']);
    }

    public function relationAction(
        Request $request,
        string $module,
        int $record,
        string $relation,
        int $item,
        string $action,
        MobileWorkspaceRegistry $registry,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $supported = ($module === 'distribusi' && in_array($relation, ['stops', 'incidents'], true))
            || ($module === 'pencucian' && $relation === 'checklistItems')
            || (in_array($module, ['pengolahan', 'pemorsian'], true) && $relation === 'preparationOutputWithdrawals');
        abort_unless($supported, 404);
        [, $parent] = $this->relationContext(
            $request, $module, $record, $relation, $registry, $systemUnit, 'action',
        );
        $child = $parent->{$relation}()->whereKey($item)->firstOrFail();
        $available = collect($this->relationActions($request, $module, $parent, $relation, $child))->pluck('key');
        abort_unless($available->contains($action), 422, 'Tindakan rincian tidak tersedia.');

        if (in_array($module, ['pengolahan', 'pemorsian'], true) && $relation === 'preparationOutputWithdrawals') {
            abort_unless($action === 'accept', 422);
            app(PreparationOutputService::class)->verifyWithdrawal(
                $child, $request->user(), (float) $child->requested_quantity,
            );
            return response()->json(['message' => 'Hasil Persiapan berhasil diterima.']);
        }

        if ($module === 'pencucian') {
            $fields = $request->validate([
                'fields' => ['nullable', 'array'],
                'fields.notes' => ['nullable', 'string', 'max:1000'],
            ])['fields'] ?? [];
            $passed = $action === 'check';
            $child->forceFill([
                'is_passed' => $passed,
                'checked_at' => $passed ? now() : null,
                'checked_by' => $passed ? $request->user()->getKey() : null,
                'notes' => filled($fields['notes'] ?? null) ? trim((string) $fields['notes']) : null,
            ])->save();

            return response()->json([
                'message' => $passed ? 'Checklist ditandai selesai.' : 'Checklist dibuka kembali.',
            ]);
        }

        if ($relation === 'incidents') {
            abort_unless($action === 'resolve', 422);
            $child->forceFill([
                'status' => DistributionIncidentStatus::Resolved,
                'resolved_at' => now(),
                'resolved_by' => $request->user()->getKey(),
            ])->save();

            return response()->json(['message' => 'Insiden distribusi ditandai selesai.']);
        }

        if (in_array($action, ['move_up', 'move_down'], true)) {
            DB::transaction(function () use ($parent, $child, $action): void {
                $orderedStops = $parent->stops()->lockForUpdate()->get()->values();
                $currentIndex = $orderedStops->search(fn (DistributionStop $stop): bool => $stop->is($child));
                $targetIndex = $action === 'move_up' ? $currentIndex - 1 : $currentIndex + 1;
                abort_unless($currentIndex !== false && $orderedStops->has($targetIndex), 422, 'Urutan tujuan tidak dapat dipindahkan lagi.');
                $target = $orderedStops->get($targetIndex);
                $currentSequence = (int) $child->sequence_order;
                $child->update(['sequence_order' => (int) $target->sequence_order]);
                $target->update(['sequence_order' => $currentSequence]);
            });

            return response()->json(['message' => 'Urutan pengantaran berhasil diperbarui.']);
        }

        $input = $request->validate([
            'fields' => ['nullable', 'array'],
            'files' => ['nullable', 'array'],
            'files.*' => ['nullable', 'string', 'max:7500000'],
        ]);
        $fields = $input['fields'] ?? [];
        $files = $input['files'] ?? [];

        if ($action === 'deliver') {
            validator(['fields' => $fields], [
                'fields.delivered_small_portions' => ['required', 'integer', 'min:0', 'max:'.(int) $child->small_portions],
                'fields.delivered_large_portions' => ['required', 'integer', 'min:0', 'max:'.(int) $child->large_portions],
                'fields.containers_sent' => ['required', 'integer', 'min:1'],
                'fields.recipient_name' => ['required', 'string', 'max:255'],
                'fields.recipient_position' => ['nullable', 'string', 'max:255'],
                'fields.failure_reason' => ['nullable', 'string', 'max:5000'],
            ])->validate();
            if (blank($child->handover_photo_path) && blank($files['handover_photo_path'] ?? null)) {
                throw ValidationException::withMessages([
                    'files.handover_photo_path' => 'Foto serah-terima wajib dipilih.',
                ]);
            }
        }

        if ($action === 'fail') {
            validator(['fields' => $fields], [
                'fields.failure_reason' => ['required', 'string', 'max:5000'],
            ])->validate();
        }

        $oldPhoto = $child->handover_photo_path;
        $storedPhoto = null;
        if (filled($files['handover_photo_path'] ?? null)) {
            $storedPhoto = $this->storeEncodedImage(
                (string) $files['handover_photo_path'],
                'mobile/distribusi/stops',
                'files.handover_photo_path',
            );
        }

        $workflow = app(DistributionWorkflow::class);
        $data = [...$child->getAttributes(), ...$fields];
        if ($storedPhoto) {
            $data['handover_photo_path'] = $storedPhoto;
        }
        try {
            match ($action) {
                'arrive' => $workflow->arriveAtStop($parent, $child, $request->user()),
                'deliver' => $workflow->completeStop($parent, $child, $request->user(), $data),
                'fail' => $workflow->failStop($parent, $child, $request->user(), $data),
            };
        } catch (\Throwable $exception) {
            if ($storedPhoto) {
                Storage::disk('public')->delete($storedPhoto);
            }
            throw $exception;
        }
        if ($storedPhoto && $oldPhoto && $storedPhoto !== $oldPhoto) {
            Storage::disk('public')->delete($oldPhoto);
        }

        return response()->json(['message' => 'Status tujuan berhasil diperbarui.']);
    }

    /** @param array<string, mixed> $definition */
    private function applyDefinitionScope(Builder $query, array $definition): void
    {
        if (! empty($definition['with'])) {
            $query->with((array) $definition['with']);
        }

        foreach ($definition['where'] ?? [] as $column => $value) {
            $query->where($column, $value);
        }

        foreach ($definition['where_in'] ?? [] as $column => $values) {
            $query->whereIn($column, (array) $values);
        }

        if (filled($definition['warehouse_type'] ?? null)) {
            $query->where(function (Builder $scopedQuery) use ($definition): void {
                $scopedQuery->whereHas('warehouse', fn (Builder $warehouseQuery) => $warehouseQuery
                    ->where('type', $definition['warehouse_type'])
                    ->where('is_active', true));
                if ($definition['warehouse_type'] === Warehouse::TYPE_FOOD) {
                    $scopedQuery->orWhereNull('warehouse_id');
                }
            });
        }
    }

    private function applyDistributionActorScope(Builder $query, string $module, $actor): void
    {
        if ($module !== 'distribusi' || $actor->can('distribution.approve')) {
            return;
        }

        $query->where(function (Builder $query) use ($actor): void {
            $query->where('state', 'planned')
                ->orWhere('petugas_id', $actor->getKey());
        });
    }

    private function assertDistributionRecordAccess(string $module, Model $item, $actor): void
    {
        if ($module !== 'distribusi' || $actor->can('distribution.approve')) {
            return;
        }

        $state = $this->scalarValue($item->getAttribute('state'));
        abort_unless(
            $state === 'planned' || (int) $item->getAttribute('petugas_id') === (int) $actor->getKey(),
            403,
            'Rute ini sedang atau sudah dikerjakan oleh driver lain.',
        );
    }

    private function addSummaryCounts(Builder $query, string $module): void
    {
        $relations = match ($module) {
            'gudang' => ['items'],
            'gudang-stok-awal', 'gudang-stok-awal-non-pangan' => ['items'],
            'gudang-stok', 'gudang-stok-non-pangan' => ['movements'],
            'gudang-pengambilan', 'gudang-pengambilan-non-pangan', 'pengambilan-non-pangan',
            'pengambilan-gudang-persiapan', 'pengambilan-gudang-pengolahan', 'pengambilan-gudang-pemorsian' => ['items'],
            'lapangan-konfirmasi' => ['items'],
            'persiapan' => ['items', 'returns'],
            'hasil-persiapan', 'hasil-persiapan-pengolahan', 'hasil-persiapan-pemorsian' => ['withdrawals'],
            'pengambilan-ompreng' => ['items'],
            'ba-limbah-persiapan', 'ba-limbah-pencucian', 'ba-limbah-kebersihan' => ['items'],
            'kebersihan' => ['checklistItems', 'findings'],
            'keamanan' => ['reports'],
            default => [],
        };

        if ($relations !== []) {
            $query->withCount($relations);
        }
    }

    /** @param array<string, mixed> $definition */
    private function findRecord(array $definition, int $record, int $unitId): Model
    {
        $query = $definition['model']::query()->where('sppg_unit_id', $unitId);
        $this->applyDefinitionScope($query, $definition);

        return $query->findOrFail($record);
    }

    /** @param array<string, mixed> $definition */
    private function validatedValues(Request $request, array $definition): array
    {
        $editableFields = collect($definition['fields'] ?? [])
            ->reject(fn (array $field): bool => ($field['editable'] ?? true) === false)
            ->reject(fn (array $field): bool => in_array($field['name'], [
                'uuid', 'state', 'status', 'created_by', 'updated_by', 'submitted_by',
                'verified_by', 'created_at', 'updated_at',
            ], true))
            ->filter(fn (array $field): bool => ($field['type'] ?? 'text') !== 'file')
            ->keyBy('name');

        $rules = ['fields' => ['present', 'array']];
        foreach ($editableFields as $name => $field) {
            $rule = [($field['required'] ?? false) ? 'required' : 'nullable'];
            $typeRule = match ($field['type'] ?? 'text') {
                'number' => 'numeric',
                'boolean' => 'boolean',
                'date', 'datetime' => 'date',
                'select' => null,
                default => 'string',
            };
            if ($typeRule !== null) {
                $rule[] = $typeRule;
            }
            if (($field['type'] ?? null) === 'select' && is_array($field['options'] ?? null)) {
                $rule[] = Rule::in(array_keys($field['options']));
            }
            $rules['fields.'.$name] = $rule;
        }

        $validated = $request->validate($rules);

        return collect($validated['fields'] ?? [])
            ->only($editableFields->keys())
            ->map(fn ($value) => $value === '' ? null : $value)
            ->all();
    }

    private function applySystemValues(Model $item, Request $request, int $unitId, bool $creating = false): void
    {
        foreach ([
            'sppg_unit_id' => $unitId,
            'updated_by' => $request->user()->getKey(),
        ] as $field => $value) {
            if ($item->isFillable($field)) {
                $item->setAttribute($field, $value);
            }
        }
        if ($creating) {
            foreach ([
                'created_by' => $request->user()->getKey(),
                'source_system' => 'mobile',
                'officer_id' => $request->user()->getKey(),
                'officer_name_snapshot' => $request->user()->name,
            ] as $field => $value) {
                if ($item->isFillable($field) && blank($item->getAttribute($field))) {
                    $item->setAttribute($field, $value);
                }
            }
        }
    }

    /** @param array<string, mixed> $definition */
    private function formFields(
        array $definition,
        Model $item,
        MobileWorkspaceRegistry $registry,
        int $unitId,
        bool $relationMode = false,
    ): array {
        return collect($definition['fields'] ?? [])
            ->reject(fn (array $field): bool => ($field['detail_only'] ?? false) && ! $item->exists)
            ->map(function (array $field) use ($item, $registry, $unitId, $relationMode): array {
                $name = $field['name'];
                $editable = ($field['editable'] ?? true)
                    && ! (($field['create_only'] ?? false) && $item->exists)
                    && ! in_array($name, $relationMode ? ['uuid', 'state'] : ['uuid', 'state', 'status'], true)
                    && ! in_array($name, ['sequence_number', 'due_at', 'reported_at'], true)
                    && ! in_array($name, ['created_by', 'updated_by', 'submitted_by', 'verified_by'], true);

                return [
                    'key' => $name,
                    'label' => $field['label'],
                    'type' => $field['type'] ?? 'text',
                    'value' => $this->formValue($item->getAttribute($name), $field['type'] ?? 'text'),
                    'file_url' => ($field['type'] ?? null) === 'file' && filled($item->getAttribute($name))
                        ? url(Storage::disk('public')->url($item->getAttribute($name)))
                        : null,
                    'required' => (bool) ($field['required'] ?? false),
                    'editable' => $editable,
                    'options' => isset($field['options'])
                        ? $registry->options($field['options'], $unitId)
                        : [],
                ];
            })->values()->all();
    }

    /** @param array<string, mixed> $definition */
    private function capabilities(Request $request, array $definition, Model $item, ?string $module = null): array
    {
        $editable = $this->isEditable($item);
        $ownsDivisionWithdrawal = ! ($module && str_starts_with($module, 'pengambilan-gudang-'))
            || (int) $item->getAttribute('taken_by') === (int) $request->user()->getKey();

        return [
            'can_update' => ($definition['allow_update'] ?? true)
                && $editable
                && $ownsDivisionWithdrawal
                && $request->user()->can($definition['permission'].'.update'),
            'can_delete' => ($definition['allow_delete'] ?? true)
                && $this->isDeletable($item)
                && $ownsDivisionWithdrawal
                && $request->user()->can($definition['permission'].'.delete'),
            'actions' => $module ? $this->availableActions($request, $module, $item) : [],
            'can_view_document' => $module !== null
                && $this->canViewDocument($request, $module, $definition, $item),
        ];
    }

    /** @param array<string,mixed> $definition */
    private function canViewDocument(Request $request, string $module, array $definition, Model $item): bool
    {
        if (! in_array($module, [
            'lapangan-laporan', 'persiapan', 'pengolahan', 'pemorsian', 'distribusi',
            'pencucian', 'kebersihan', 'ba-limbah-persiapan',
            'ba-limbah-pencucian', 'ba-limbah-kebersihan',
        ], true)) {
            return false;
        }

        if (str_starts_with($module, 'ba-limbah-')) {
            return $request->user()->can($definition['permission'].'.view');
        }

        if (in_array($module, ['persiapan', 'pengolahan', 'pencucian'], true)
            && $this->scalarValue($item->getAttribute('status')) !== OperationalReportStatus::Verified->value) {
            return false;
        }

        return $request->user()->can($definition['permission'].'.export');
    }

    /** @return array<int, array<string,mixed>> */
    private function availableActions(Request $request, string $module, Model $item): array
    {
        $state = $this->scalarValue($item->getAttribute('state'));
        $status = $this->scalarValue($item->getAttribute('status'));
        $actor = $request->user();
        $actions = [];

        if (in_array($module, ['gudang', 'gudang-non-pangan'], true) && $status === StockReceipt::STATUS_DRAFT
            && $actor->can($module === 'gudang-non-pangan' ? 'non_food_stock.update' : 'stock.update')) {
            return [$this->actionDefinition('receive', 'Masukkan barang ke kartu stok')];
        }

        if (in_array($module, ['gudang-stok', 'gudang-stok-non-pangan'], true)
            && $actor->can($module === 'gudang-stok-non-pangan' ? 'non_food_stock.update' : 'stock.update')) {
            return [
                $this->actionDefinition('adjust_stock', 'Sesuaikan dengan stok fisik', false, [
                    $this->actionField(
                        'actual_quantity',
                        'Jumlah fisik aktual',
                        'number',
                        true,
                        (string) $item->balance_quantity,
                    ),
                    $this->actionField('adjustment_type', 'Jenis penyesuaian', 'select', true, 'stock_opname', [
                        'stock_opname' => 'Stok opname',
                        'return_from_division' => 'Retur dari divisi',
                        'damage' => 'Rusak',
                    ]),
                    $this->actionField('reason', 'Alasan penyesuaian', 'textarea', true),
                ]),
                $this->actionDefinition('update_lot', 'Ubah lokasi atau status lot', false, [
                    $this->actionField('location_name', 'Lokasi penyimpanan', 'text', true, (string) $item->location_name),
                    $this->actionField('storage_type', 'Jenis penyimpanan', 'select', true, (string) $item->storage_type, [
                        'wet' => 'Gudang basah',
                        'dry' => 'Gudang kering',
                        'freezer' => 'Freezer',
                        'chiller' => 'Chiller',
                    ]),
                    $this->actionField('lot_status', 'Status lot', 'select', true, (string) $item->status, [
                        'available' => 'Tersedia',
                        'quarantine' => 'Karantina',
                        'rejected' => 'Ditolak',
                        'depleted' => 'Habis',
                    ]),
                ]),
            ];
        }

        if (in_array($module, ['gudang-penyesuaian', 'gudang-penyesuaian-non-pangan'], true)
            && $status === StockAdjustment::DRAFT
            && $actor->can($module === 'gudang-penyesuaian-non-pangan' ? 'non_food_stock.approve' : 'stock.approve')) {
            return [$this->actionDefinition('verify', 'Verifikasi dan perbarui stok')];
        }

        if ($module === 'lapangan-konfirmasi'
            && $item->isEditable()
            && $actor->can('daily_beneficiary_confirmations.submit')) {
            return [$this->actionDefinition('confirm', 'Simpan konfirmasi penerima harian')];
        }

        if ((str_starts_with($module, 'pengambilan-gudang-') || $module === 'pengambilan-non-pangan')
            && in_array($status, [WarehouseWithdrawal::DRAFT, WarehouseWithdrawal::REVISION], true)
            && (int) $item->taken_by === (int) $actor->getKey()) {
            return [$this->actionDefinition('submit', 'Ajukan untuk verifikasi Gudang')];
        }

        if (in_array($module, ['gudang-pengambilan', 'gudang-pengambilan-non-pangan'], true)
            && $status === WarehouseWithdrawal::WAITING
            && $actor->can($module === 'gudang-pengambilan-non-pangan' ? 'non_food_stock.approve' : 'stock.approve')) {
            return [
                $this->actionDefinition('verify', 'Verifikasi pengambilan'),
                $this->actionDefinition('revision', 'Minta koreksi', true),
                $this->actionDefinition('reject', 'Tolak pengambilan', true),
            ];
        }

        if ($module === 'gudang-retur'
            && $status === PreparationReturn::WAITING
            && $actor->can('stock.approve')) {
            return [
                $this->actionDefinition('verify', 'Terima retur ke Gudang', false, [
                    $this->actionField(
                        'actual_quantity',
                        'Jumlah aktual',
                        'number',
                        true,
                        (string) ($item->actual_quantity ?: $item->requested_quantity),
                    ),
                    $this->actionField('warehouse_disposition', 'Keputusan Gudang', 'select', true, null, [
                        'available' => 'Kembali tersedia',
                        'quarantine' => 'Karantina',
                        'rejected' => 'Ditolak/rusak',
                    ]),
                ]),
                $this->actionDefinition('reject', 'Tolak retur', true),
            ];
        }

        if ($module === 'gudang-retur-pengolahan'
            && $status === ProcessingReturn::WAITING
            && $actor->can('stock.approve')) {
            return [
                $this->actionDefinition('verify', 'Terima retur Pengolahan', false, [
                    $this->actionField(
                        'actual_quantity',
                        'Jumlah aktual',
                        'number',
                        true,
                        (string) ($item->actual_quantity ?: $item->requested_quantity),
                    ),
                    $this->actionField('warehouse_disposition', 'Keputusan Gudang', 'select', true, null, [
                        'available' => 'Kembali tersedia',
                        'quarantine' => 'Karantina',
                        'rejected' => 'Ditolak/rusak',
                    ]),
                ]),
                $this->actionDefinition('reject', 'Tolak retur Pengolahan', true),
            ];
        }

        if (in_array($module, ['hasil-persiapan-pengolahan', 'hasil-persiapan-pemorsian'], true)
            && $actor->can(($module === 'hasil-persiapan-pengolahan' ? 'processing' : 'portioning').'.update')
            && $item->isAvailableFor($module === 'hasil-persiapan-pengolahan' ? 'processing' : 'portioning')) {
            $division = $module === 'hasil-persiapan-pengolahan' ? 'processing' : 'portioning';
            $targetField = $division === 'processing' ? 'processing_batch_id' : 'portioning_session_id';
            $targetLabel = $division === 'processing' ? 'Batch Pengolahan' : 'Sesi Pemorsian';
            $targetOptions = $division === 'processing'
                ? ProcessingBatch::query()
                    ->where('sppg_unit_id', $item->sppg_unit_id)
                    ->where('state', 'in_progress')
                    ->latest('production_date')->limit(100)->pluck('batch_number', 'id')->mapWithKeys(
                        fn ($label, $id): array => [(string) $id => (string) $label],
                    )->all()
                : PortioningSession::query()
                    ->where('sppg_unit_id', $item->sppg_unit_id)
                    ->where('state', 'in_progress')
                    ->latest('portioning_date')->limit(100)->pluck('session_number', 'id')->mapWithKeys(
                        fn ($label, $id): array => [(string) $id => (string) $label],
                    )->all();

            return [$this->actionDefinition('request_withdrawal', 'Ambil Hasil Persiapan', false, [
                $this->actionField('requested_quantity', 'Jumlah yang diambil', 'number', true),
                $this->actionField($targetField, $targetLabel, 'select', true, null, $targetOptions),
            ])];
        }

        if ($module === 'hasil-persiapan' && $actor->can('preparation.update')) {
            $pendingOptions = $item->withdrawals()
                ->where('status', PreparationOutputWithdrawal::WAITING)
                ->get()
                ->mapWithKeys(fn ($withdrawal): array => [
                    (string) $withdrawal->getKey() => sprintf(
                        '%s - %s %s',
                        ucfirst((string) $withdrawal->destination_division),
                        rtrim(rtrim(number_format((float) $withdrawal->requested_quantity, 4, '.', ''), '0'), '.'),
                        $withdrawal->unit_snapshot,
                    ),
                ])->all();
            if ($pendingOptions !== []) {
                return [
                    $this->actionDefinition('verify_withdrawal', 'Verifikasi pengambilan', false, [
                        $this->actionField('withdrawal_id', 'Pengambilan', 'select', true, null, $pendingOptions),
                        $this->actionField('verified_quantity', 'Jumlah aktual', 'number', true),
                    ]),
                    $this->actionDefinition('reject_withdrawal', 'Tolak pengambilan', true, [
                        $this->actionField('withdrawal_id', 'Pengambilan', 'select', true, null, $pendingOptions),
                    ]),
                ];
            }
        }

        if ($module === 'pengambilan-ompreng' && $state === ContainerCollectionRun::ACTIVE
            && $actor->can('distribution.update')) {
            $taskOptions = ContainerCollectionTask::query()
                ->where('sppg_unit_id', $item->sppg_unit_id)
                ->whereIn('status', [
                    ContainerCollectionTask::PENDING,
                    ContainerCollectionTask::PARTIAL,
                ])
                ->where('remaining_containers', '>', 0)
                ->orderBy('delivery_date')
                ->orderBy('destination_name')
                ->get()
                ->mapWithKeys(fn ($task): array => [
                    (string) $task->getKey() => $task->destination_name.' (sisa '.$task->remaining_containers.')',
                ])->all();
            if ($taskOptions !== []) {
                $commonFields = [
                    $this->actionField('task_id', 'Sekolah/Posyandu', 'select', true, null, $taskOptions),
                    $this->actionField('photo_path', 'Foto pengambilan', 'file'),
                ];
                $actions[] = $this->actionDefinition(
                    'collect_all',
                    'Ambil seluruh sisa ompreng',
                    false,
                    $commonFields,
                );
                $actions[] = $this->actionDefinition(
                    'collect_partial',
                    'Ambil sebagian ompreng',
                    true,
                    [
                        $commonFields[0],
                        $this->actionField('quantity', 'Jumlah yang berhasil diambil', 'number', true),
                        $commonFields[1],
                    ],
                );
            }
            if ($item->items()->exists()) {
                $actions[] = $this->actionDefinition('return', 'Kembali ke SPPG');
            }

            return $actions;
        }

        if ($module === 'lapangan-laporan') {
            if (in_array($status, ['draft', 'revision_required'], true)
                && $actor->can('field_daily_reports.submit')) {
                $actions[] = $this->actionDefinition('submit', 'Ajukan laporan harian');
            }
            if ($status === 'submitted' && $actor->can('field_daily_reports.approve')) {
                $actions[] = $this->actionDefinition('approve', 'Setujui laporan');
                $actions[] = $this->actionDefinition('revision', 'Minta revisi', true);
            }

            return $actions;
        }

        if ($module === 'lapangan-insiden' && $actor->can('field_incidents.update')) {
            if ($status === 'open') {
                $actions[] = $this->actionDefinition('start_follow_up', 'Mulai tindak lanjut');
            }
            if (in_array($status, ['open', 'in_progress'], true)) {
                $actions[] = $this->actionDefinition('resolve', 'Selesaikan insiden', false, [
                    $this->actionField('resolution', 'Penyelesaian', 'textarea', true),
                    $this->actionField('root_cause', 'Akar masalah', 'textarea'),
                    $this->actionField('immediate_action', 'Tindakan langsung', 'textarea'),
                ]);
            }

            return $actions;
        }

        $permission = match ($module) {
            'persiapan' => 'preparation',
            'pengolahan' => 'processing',
            'pemorsian' => 'portioning',
            'distribusi', 'pengambilan-ompreng' => 'distribution',
            'pencucian' => 'washing',
            'kebersihan' => 'cleaning',
            'ba-limbah-persiapan' => 'preparation',
            'ba-limbah-pencucian' => 'washing',
            'ba-limbah-kebersihan' => 'cleaning',
            default => null,
        };
        if ($permission === null) {
            return [];
        }

        if ($actor->can($permission.'.update')) {
            $actions = match ($module) {
                'persiapan', 'pengolahan', 'pemorsian' => match ($state) {
                    'planned' => [$this->actionDefinition('start', 'Mulai pekerjaan')],
                    'in_progress' => $module === 'pengolahan'
                        ? array_values(array_filter([
                            $this->actionDefinition('complete', 'Selesaikan & Serahkan ke Pemorsian'),
                            app(ProcessingWorkflow::class)->canCancel($item)
                                ? $this->actionDefinition('cancel', 'Batalkan produksi', true)
                                : null,
                        ]))
                        : ($module === 'pemorsian'
                            ? array_values(array_filter([
                                $this->actionDefinition('set_leftover_none', 'Tidak ada sisa makanan'),
                                $this->actionDefinition('set_leftover_present', 'Ada sisa makanan'),
                                $this->actionDefinition('complete', 'Selesaikan pekerjaan'),
                                app(PortioningWorkflow::class)->canCancel($item)
                                    ? $this->actionDefinition('cancel', 'Batalkan Pemorsian', true)
                                    : null,
                            ]))
                            : [$this->actionDefinition('complete', 'Selesaikan pekerjaan')]),
                    default => [],
                },
                'distribusi' => ! $actor->can('distribution.approve')
                    && $state !== 'planned'
                    && (int) $item->getAttribute('petugas_id') !== (int) $actor->getKey()
                        ? []
                        : match ($state) {
                            'planned' => [$this->actionDefinition('claim', 'Pilih rute ini', false, [
                                $this->actionField('vehicle_name', 'Jenis/nama kendaraan', 'text', true, (string) $item->vehicle_name),
                                $this->actionField('vehicle_plate', 'Nomor polisi', 'text', true, (string) $item->vehicle_plate),
                                $this->actionField('kernet_name', 'Nama kernet', 'text', true, (string) $item->kernet_name),
                            ])],
                            'assigned' => [
                                $this->actionDefinition('load', 'Mulai memuat'),
                                $this->actionDefinition('release', 'Lepas rute'),
                            ],
                            'loaded' => [
                                $this->actionDefinition('depart', 'Berangkat', false, [
                                    $this->actionField('actual_departure_at', 'Waktu berangkat', 'datetime', true, now()->format('Y-m-d\TH:i')),
                                    $this->actionField('departure_temperature_celsius', 'Suhu makanan saat berangkat °C', 'number', true, (string) $item->departure_temperature_celsius),
                                ]),
                                $this->actionDefinition('release', 'Lepas rute'),
                            ],
                            'destinations_completed' => [$this->actionDefinition('finish', 'Sudah kembali ke SPPG', false, [
                                $this->actionField('returned_at', 'Waktu kembali', 'datetime', true, now()->format('Y-m-d\TH:i')),
                            ])],
                            default => [],
                        },
                'pencucian' => match ($state) {
                    'planned' => [$this->actionDefinition('receive', 'Terima ompreng', false, [
                        $this->actionField('received_containers', 'Jumlah diterima fisik', 'number', true, (string) ($item->received_containers ?: $item->expected_containers)),
                        $this->actionField('damaged_containers', 'Jumlah rusak/tidak layak', 'number', true, (string) ($item->damaged_containers ?: 0)),
                        $this->actionField('notes', 'Catatan selisih/kondisi', 'textarea', false, (string) $item->notes),
                    ])],
                    'received' => $item->has_food_waste === null
                        ? [
                            $this->actionDefinition('waste_none', 'Tidak ada sisa makanan'),
                            $this->actionDefinition('waste_present', 'Ada sisa makanan'),
                        ]
                        : ($item->has_food_waste
                            ? ($item->wasteHandlingCompleted()
                                ? [$this->actionDefinition('start', 'Mulai pencucian')]
                                : [
                                    $this->actionDefinition('waste_none', 'Ubah: tidak ada sisa makanan'),
                                    $this->actionDefinition('waste', 'Simpan data dan berita acara limbah', false, [
                                        $this->actionField('waste_first_party_name', 'Nama pihak penyerah', 'text', true, (string) ($item->waste_first_party_name ?: $item->petugas_name_snapshot)),
                                        $this->actionField('waste_first_party_position', 'Jabatan pihak penyerah', 'text', true, (string) $item->waste_first_party_position),
                                        $this->actionField('waste_first_party_address', 'Alamat pihak penyerah', 'textarea', true, (string) $item->waste_first_party_address),
                                        $this->actionField('waste_second_party_name', 'Nama penerima limbah', 'text', true, (string) $item->waste_second_party_name),
                                        $this->actionField('waste_second_party_position', 'Jabatan penerima limbah', 'text', true, (string) $item->waste_second_party_position),
                                        $this->actionField('waste_second_party_address', 'Alamat penerima limbah', 'textarea', true, (string) $item->waste_second_party_address),
                                        $this->actionField('waste_handover_notes', 'Catatan serah-terima', 'textarea', false, (string) $item->waste_handover_notes),
                                    ]),
                                ])
                            : [
                                $this->actionDefinition('waste_present', 'Ubah: ada sisa makanan'),
                                $this->actionDefinition('start', 'Mulai pencucian'),
                            ]),
                    'washing' => [$this->actionDefinition('complete', 'Selesaikan pencucian', false, [
                        $this->actionField('clean_containers', 'Ompreng bersih dan siap', 'number', true, (string) $item->clean_containers),
                        $this->actionField('damaged_containers', 'Ompreng rusak/tidak layak', 'number', true, (string) $item->damaged_containers),
                        $this->actionField('notes', 'Catatan hasil pencucian', 'textarea', false, (string) $item->notes),
                    ])],
                    'completed' => [$this->actionDefinition('ready', 'Tandai siap digunakan')],
                    default => [],
                },
                'kebersihan' => match ($state) {
                    'planned' => [$this->actionDefinition('start', 'Mulai kebersihan', false, [
                        $this->actionField('started_at', 'Waktu mulai', 'datetime', true, now()->format('Y-m-d\TH:i')),
                        $this->actionField('notes', 'Catatan mulai', 'textarea', false, (string) $item->notes),
                    ])],
                    'in_progress' => [$this->actionDefinition('complete', 'Selesaikan kebersihan', false, [
                        $this->actionField('completed_at', 'Waktu selesai', 'datetime', true, now()->format('Y-m-d\TH:i')),
                        $this->actionField('after_condition', 'Evaluasi kondisi setelah dibersihkan', 'textarea', true, (string) $item->after_condition),
                        $this->actionField('notes', 'Catatan hasil', 'textarea', false, (string) $item->notes),
                    ])],
                    default => [],
                },
                default => [],
            };

            if ($module === 'persiapan' && $state === 'completed') {
                foreach (['processing' => 'Pengolahan', 'portioning' => 'Pemorsian'] as $division => $label) {
                    $outputs = $item->outputs()->where('target_division', $division)
                        ->where('available_quantity', '>', 0)
                        ->get()
                        ->mapWithKeys(fn ($output): array => [
                            (string) $output->getKey() => $output->output_name.' · '.number_format((float) $output->available_quantity, 3, ',', '.').' '.$output->unit_snapshot,
                        ])->all();
                    if ($outputs !== []) {
                        $actions[] = $this->actionDefinition('handover_'.$division, 'Serahkan ke '.$label, false, [
                            $this->actionField('preparation_output_id', 'Hasil siap', 'select', true, null, $outputs),
                            $this->actionField('requested_quantity', 'Jumlah diserahkan', 'number', true),
                        ]);
                    }
                }
            }

            if (in_array($module, ['pengolahan', 'pemorsian'], true) && $state === 'in_progress') {
                $division = $module === 'pengolahan' ? 'processing' : 'portioning';
                $targetColumn = $division === 'processing' ? 'processing_batch_id' : 'portioning_session_id';
                $pendingOutputs = PreparationOutputWithdrawal::query()
                    ->with('output')
                    ->where('destination_division', $division)
                    ->where('status', PreparationOutputWithdrawal::WAITING)
                    ->whereNull($targetColumn)
                    ->whereHas('output', fn (Builder $query) => $query->where('sppg_unit_id', $item->sppg_unit_id))
                    ->orderBy('taken_at')
                    ->get()
                    ->mapWithKeys(fn (PreparationOutputWithdrawal $withdrawal): array => [
                        (string) $withdrawal->getKey() => sprintf(
                            '%s · %s %s',
                            $withdrawal->output?->output_name,
                            number_format((float) $withdrawal->requested_quantity, 3, ',', '.'),
                            $withdrawal->unit_snapshot,
                        ),
                    ])->all();
                if ($pendingOutputs !== []) {
                    array_unshift($actions, $this->actionDefinition(
                        'receive_preparation_output',
                        'Terima Hasil Persiapan',
                        false,
                        [$this->actionField('withdrawal_id', 'Hasil yang diserahkan', 'select', true, null, $pendingOutputs)],
                    ));
                }
            }

            if ($module === 'pemorsian' && in_array($state, ['planned', 'in_progress'], true)) {
                $ready = ProcessingBatch::query()
                    ->where('sppg_unit_id', $item->sppg_unit_id)
                    ->whereNotNull('portioning_handed_over_at')->whereNull('portioning_received_at')
                    ->latest('portioning_handed_over_at')->get()
                    ->mapWithKeys(fn (ProcessingBatch $batch): array => [
                        (string) $batch->getKey() => $batch->batch_number.' · '.$batch->product_name.' · '.number_format((float) $batch->actual_output_quantity, 3, ',', '.').' '.$batch->actual_output_unit,
                    ])->all();
                if ($ready !== []) {
                    array_unshift($actions, $this->actionDefinition('receive_processing_batch', 'Terima Batch Pengolahan', false, [
                        $this->actionField('processing_batch_id', 'Batch siap', 'select', true, null, $ready),
                    ]));
                }
            }
        }

        if (in_array($state, ['completed', 'ready', 'returned'], true)
            && in_array($status, ['draft', 'revision_required'], true)
            && $actor->can($permission.'.submit')
            && ($module !== 'distribusi' || ! ($item instanceof DistributionRun) || $item->allRoutesReturned())
            && ($module !== 'pencucian' || ! ($item instanceof WashingSession)
                || app(WashingWorkflow::class)->submissionIssues($item) === [])) {
            $actions[] = $this->actionDefinition('submit', 'Ajukan laporan');
        }
        if (str_starts_with($module, 'ba-limbah-')
            && $module !== 'ba-limbah-persiapan'
            && in_array($status, ['draft', 'revision_required'], true)
            && $actor->can($permission.'.submit')) {
            $actions[] = $this->actionDefinition('submit', 'Ajukan berita acara');
        }
        if ($module !== 'ba-limbah-persiapan'
            && in_array($status, ['submitted', 'division_approved'], true)
            && $actor->can($permission.'.approve')) {
            $actions[] = $this->actionDefinition('verify', 'Setujui laporan');
            $actions[] = $this->actionDefinition('revision', 'Minta revisi', true);
        }

        return $actions;
    }

    /** @param array<int,array<string,mixed>> $fields */
    private function actionDefinition(
        string $key,
        string $label,
        bool $notesRequired = false,
        array $fields = [],
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'notes_required' => $notesRequired,
            'fields' => $fields,
        ];
    }

    /** @param array<string,string> $options */
    private function actionField(
        string $key,
        string $label,
        string $type = 'text',
        bool $required = false,
        ?string $value = null,
        array $options = [],
    ): array {
        return compact('key', 'label', 'type', 'required', 'value', 'options') + ['editable' => true];
    }

    /** @param array<string,mixed> $fields */
    private function runWarehouseStockAction(
        string $action,
        Model $item,
        $actor,
        array $fields,
        ?string $notes,
    ): Model {
        abort_unless($actor->can('stock.update'), 403);
        $service = app(StockControlService::class);

        if ($action === 'update_lot') {
            $service->updateLot(
                $item,
                (string) ($fields['location_name'] ?? ''),
                (string) ($fields['storage_type'] ?? ''),
                (string) ($fields['lot_status'] ?? ''),
            );

            return $item->refresh();
        }

        abort_unless($action === 'adjust_stock', 422);
        $type = (string) ($fields['adjustment_type'] ?? '');
        if (! in_array($type, ['stock_opname', 'return_from_division', 'damage'], true)) {
            throw ValidationException::withMessages(['fields.adjustment_type' => 'Jenis penyesuaian tidak valid.']);
        }
        $reason = trim((string) ($fields['reason'] ?? $notes ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['fields.reason' => 'Alasan penyesuaian wajib diisi.']);
        }
        $actual = filter_var($fields['actual_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($actual === false || $actual < 0) {
            throw ValidationException::withMessages(['fields.actual_quantity' => 'Jumlah fisik aktual harus nol atau lebih.']);
        }

        $service->create($item, (float) $actual, $type, $reason, $actor);

        return $item->refresh();
    }

    private function runWarehouseAdjustmentAction(string $action, Model $item, $actor): Model
    {
        abort_unless($action === 'verify', 422);
        abort_unless($actor->can('stock.approve'), 403);
        app(StockControlService::class)->verify($item, $actor);

        return $item->refresh();
    }

    private function runWarehouseReceiptAction(string $action, Model $item, $actor): Model
    {
        abort_unless($action === 'receive', 422);
        abort_unless($actor->can('stock.update'), 403);
        $item->forceFill([
            'received_by' => $actor->getKey(),
            'received_by_name' => $actor->name,
        ])->save();

        return app(StockReceiptService::class)->receive($item);
    }

    private function runDivisionWarehouseWithdrawalAction(
        string $action,
        Model $item,
        $actor,
    ): Model {
        abort_unless($action === 'submit', 422);

        return app(WarehouseWithdrawalService::class)
            ->submitMobileDraft($item, $actor);
    }

    private function runDailyBeneficiaryConfirmationAction(
        string $action,
        Model $item,
        $actor,
    ): Model {
        abort_unless($action === 'confirm', 422);

        return app(MobileDailyBeneficiaryConfirmationService::class)
            ->confirm($item, $actor);
    }

    private function runWarehouseWithdrawalAction(
        string $action,
        Model $item,
        $actor,
        ?string $notes,
    ): Model {
        $service = app(WarehouseWithdrawalService::class);

        return match ($action) {
            'verify' => $service->verify($item, $actor),
            'revision' => $service->requestRevision($item, $actor, (string) $notes),
            'reject' => $service->reject($item, $actor, (string) $notes),
        };
    }

    /** @param array<string,mixed> $fields */
    private function runPreparationReturnAction(
        string $action,
        Model $item,
        $actor,
        array $fields,
        ?string $notes,
    ): Model {
        $service = app(PreparationReturnService::class);

        return match ($action) {
            'verify' => $service->verify(
                $item,
                (float) ($fields['actual_quantity'] ?? 0),
                (string) ($fields['warehouse_disposition'] ?? ''),
                $notes,
                $actor,
            ),
            'reject' => $service->reject($item, (string) $notes, $actor),
        };
    }

    /** @param array<string,mixed> $fields */
    private function runProcessingReturnAction(
        string $action,
        Model $item,
        $actor,
        array $fields,
        ?string $notes,
    ): Model {
        $service = app(ProcessingReturnService::class);

        return match ($action) {
            'verify' => $service->verify(
                $item,
                (float) ($fields['actual_quantity'] ?? 0),
                (string) ($fields['warehouse_disposition'] ?? ''),
                $notes,
                $actor,
            ),
            'reject' => $service->reject($item, (string) $notes, $actor),
        };
    }

    /** @param array<string,mixed> $fields */
    private function runPreparationOutputAction(
        string $module,
        string $action,
        Model $item,
        $actor,
        array $fields,
        ?string $notes,
    ): Model {
        $service = app(PreparationOutputService::class);
        if ($action === 'request_withdrawal') {
            $division = $module === 'hasil-persiapan-pengolahan' ? 'processing' : 'portioning';
            $service->requestWithdrawal($item, $actor, [
                'destination_division' => $division,
                'requested_quantity' => $fields['requested_quantity'] ?? null,
                'processing_batch_id' => $fields['processing_batch_id'] ?? null,
                'portioning_session_id' => $fields['portioning_session_id'] ?? null,
                'notes' => $notes,
            ]);

            return $item->refresh();
        }

        $withdrawal = $item->withdrawals()
            ->whereKey((int) ($fields['withdrawal_id'] ?? 0))
            ->firstOrFail();

        return match ($action) {
            'verify_withdrawal' => tap($item, fn () => $service->verifyWithdrawal(
                $withdrawal,
                $actor,
                (float) ($fields['verified_quantity'] ?? 0),
                $notes,
            ))->refresh(),
            'reject_withdrawal' => tap($item, fn () => $service->rejectWithdrawal(
                $withdrawal,
                $actor,
                (string) $notes,
            ))->refresh(),
        };
    }

    /** @param array<string,mixed> $fields */
    private function runContainerCollectionAction(
        string $action,
        Model $item,
        $actor,
        array $fields,
        ?string $notes,
        ?string $photoPath,
    ): Model {
        $service = app(ContainerCollectionWorkflow::class);
        if ($action === 'return') {
            return $service->returnToSppg($item, $actor);
        }

        $task = ContainerCollectionTask::query()
            ->where('sppg_unit_id', $item->sppg_unit_id)
            ->findOrFail((int) ($fields['task_id'] ?? 0));
        if ($action === 'collect_partial') {
            $service->collectPartial(
                $item,
                $task,
                $actor,
                (int) ($fields['quantity'] ?? 0),
                (string) $notes,
                $photoPath,
            );
        } else {
            abort_unless($action === 'collect_all', 422, 'Jenis pengambilan ompreng tidak valid.');
            $service->collectAll($item, $task, $actor, $photoPath);
        }

        return $item->refresh();
    }

    private function runFieldDailyReportAction(
        string $action,
        Model $item,
        $actor,
        ?string $notes,
    ): Model {
        $service = app(FieldDailyReportWorkflow::class);
        match ($action) {
            'submit' => $service->submit($item, $actor, $notes),
            'approve' => $service->approve($item, $actor, $notes),
            'revision' => $service->requestRevision($item, $actor, (string) $notes),
        };

        return $item->refresh();
    }

    /** @param array<string,mixed> $fields */
    private function runFieldIncidentAction(
        string $action,
        Model $item,
        $actor,
        array $fields,
        ?string $notes,
    ): Model {
        abort_unless($actor->can('field_incidents.update'), 403);
        if ($action === 'start_follow_up') {
            $item->forceFill([
                'status' => FieldIncidentStatus::InProgress,
                'responsible_user_id' => $item->responsible_user_id ?: $actor->getKey(),
                'responsible_name_snapshot' => $item->responsible_name_snapshot ?: $actor->name,
                'immediate_action' => $notes ?: $item->immediate_action,
                'updated_by' => $actor->getKey(),
            ])->save();

            return $item->refresh();
        }

        $resolution = trim((string) ($fields['resolution'] ?? ''));
        if ($resolution === '') {
            throw ValidationException::withMessages(['fields.resolution' => 'Penyelesaian wajib diisi.']);
        }
        $item->forceFill([
            'status' => FieldIncidentStatus::Resolved,
            'resolution' => $resolution,
            'root_cause' => trim((string) ($fields['root_cause'] ?? '')) ?: $item->root_cause,
            'immediate_action' => trim((string) ($fields['immediate_action'] ?? '')) ?: $item->immediate_action,
            'resolved_at' => now(),
            'resolved_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        return $item->refresh();
    }

    private function runPreparationAction(string $action, Model $item, $actor, array $fields, ?string $notes): Model
    {
        if (in_array($action, ['handover_processing', 'handover_portioning'], true)) {
            $division = $action === 'handover_processing' ? 'processing' : 'portioning';
            $output = $item->outputs()->where('target_division', $division)->findOrFail((int) ($fields['preparation_output_id'] ?? 0));
            app(PreparationOutputService::class)->requestWithdrawal($output, $actor, [
                'destination_division' => $division,
                'requested_quantity' => (float) ($fields['requested_quantity'] ?? 0),
                'defer_target' => true,
                'notes' => $notes,
            ]);
            return $item->refresh();
        }
        $service = app(PreparationSessionService::class);
        match ($action) {
            'start' => $service->start($item, $actor),
            'complete' => $service->complete($item, $actor),
            'submit' => $service->submit($item, $actor),
            'verify' => $service->approve($item, $actor, $notes),
            'revision' => $service->requestRevision($item, $actor, (string) $notes),
        };

        return $item;
    }

    private function runPreparationOutputReceive(Model $target, $actor, int $withdrawalId): Model
    {
        abort_unless($target instanceof ProcessingBatch || $target instanceof PortioningSession, 422);
        $withdrawal = PreparationOutputWithdrawal::query()->findOrFail($withdrawalId);
        app(PreparationOutputService::class)->receiveUnassignedWithdrawal($withdrawal, $target, $actor);

        return $target->refresh();
    }

    private function runStandardAction(object $service, string $action, Model $item, $actor, ?string $notes): Model
    {
        return match ($action) {
            'start', 'complete' => $service->{$action}($item, $actor),
            'cancel' => $service->cancel($item, $actor, (string) $notes),
            'submit' => $service->submit($item, $actor, $notes),
            'verify' => $service->verify($item, $actor, $notes),
            'revision' => $service->requestRevision($item, $actor, (string) $notes),
        };
    }

    private function runPortioningAction(string $action, Model $item, $actor, ?string $notes): Model
    {
        if (in_array($action, ['set_leftover_none', 'set_leftover_present'], true)) {
            abort_unless($item instanceof PortioningSession && $item->state->value === 'in_progress', 422);
            $mode = $action === 'set_leftover_present' ? 'present' : 'none';

            return DB::transaction(function () use ($item, $actor, $mode): Model {
                $item = PortioningSession::query()->lockForUpdate()->findOrFail($item->getKey());
                if ($mode === 'none') {
                    $photos = $item->leftoverRecords()->pluck('photo_path')->filter()->all();
                    $item->leftoverRecords()->delete();
                    Storage::disk('public')->delete($photos);
                }
                $item->update([
                    'leftover_mode' => $mode,
                    'updated_by' => $actor->getKey(),
                ]);

                return $item->refresh();
            });
        }

        return $this->runStandardAction(app(PortioningWorkflow::class), $action, $item, $actor, $notes);
    }

    private function runPortioningReceiveBatch(Model $item, $actor, int $batchId): Model
    {
        abort_unless($item instanceof PortioningSession && $batchId > 0, 422);
        $batch = ProcessingBatch::query()->where('sppg_unit_id', $item->sppg_unit_id)->findOrFail($batchId);
        app(ProcessingPortioningHandoverService::class)->receive($batch, $item, $actor);
        return $item->refresh();
    }

    private function runDistributionAction(string $action, Model $item, $actor, array $data, ?string $notes): Model
    {
        $service = app(DistributionWorkflow::class);

        return match ($action) {
            'claim' => $service->claimRoute($item, $actor, $data),
            'release' => $service->releaseRoute($item, $actor, $notes),
            'load' => $service->startLoading($item, $actor),
            'depart' => $service->depart($item, $actor, $data),
            'finish' => $service->finish($item, $actor, $data),
            'submit' => $service->submit($item, $actor, $notes),
            'verify' => $service->verify($item, $actor, $notes),
            'revision' => $service->requestRevision($item, $actor, (string) $notes),
        };
    }

    private function runWashingAction(string $action, Model $item, $actor, array $data, ?string $notes): Model
    {
        $service = app(WashingWorkflow::class);

        return match ($action) {
            'receive' => $service->receive($item, $actor, $data),
            'waste_present' => $this->selectWashingWasteMode($item, $actor, true),
            'waste_none' => $service->recordWaste($item, $actor, [
                ...$data,
                'has_food_waste' => false,
                'no_waste_confirmed' => true,
            ]),
            'waste' => $this->recordMobileWashingWaste($item, $actor, $data),
            'start' => $service->start($item, $actor, $data),
            'complete' => $service->complete($item, $actor, $data),
            'ready' => $service->markReady($item, $actor, $notes),
            'submit' => $service->submit($item, $actor, $notes),
            'verify' => $service->verify($item, $actor, $notes),
            'revision' => $service->requestRevision($item, $actor, (string) $notes),
        };
    }

    private function selectWashingWasteMode(Model $item, $actor, bool $hasWaste): Model
    {
        abort_unless($item instanceof WashingSession && $item->state->value === 'received', 422);
        $item->forceFill([
            'has_food_waste' => $hasWaste,
            'no_waste_confirmed' => false,
            'waste_recorded_at' => null,
            'updated_by' => $actor->getKey(),
        ])->save();

        return $item->refresh();
    }

    /** @param array<string,mixed> $data */
    private function recordMobileWashingWaste(Model $item, $actor, array $data): Model
    {
        abort_unless($item instanceof WashingSession && $item->state->value === 'received', 422);
        $validated = validator($data, [
            'waste_first_party_name' => ['required', 'string', 'max:255'],
            'waste_first_party_position' => ['required', 'string', 'max:255'],
            'waste_first_party_address' => ['required', 'string', 'max:2000'],
            'waste_second_party_name' => ['required', 'string', 'max:255'],
            'waste_second_party_position' => ['required', 'string', 'max:255'],
            'waste_second_party_address' => ['required', 'string', 'max:2000'],
            'waste_handover_notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $item->forceFill([
            ...$validated,
            'has_food_waste' => true,
            'no_waste_confirmed' => false,
            'updated_by' => $actor->getKey(),
        ])->save();
        $item = $this->ensureWashingWasteReport($item->refresh(), $actor);

        return app(WashingWorkflow::class)->recordWaste($item, $actor, [
            ...$validated,
            'has_food_waste' => true,
            'no_waste_confirmed' => false,
        ]);
    }

    private function runCleaningAction(string $action, Model $item, $actor, array $data, ?string $notes): Model
    {
        $service = app(CleaningWorkflow::class);

        return match ($action) {
            'start' => $service->start($item, $actor, $data),
            'complete' => $service->complete($item, $actor, $data),
            'submit' => $service->submit($item, $actor, $notes),
            'verify' => $service->verify($item, $actor, $notes),
            'revision' => $service->requestRevision($item, $actor, (string) $notes),
        };
    }

    private function runWasteHandoverAction(string $action, Model $item, $actor, ?string $notes): Model
    {
        $service = app(WasteHandoverWorkflow::class);

        return match ($action) {
            'submit' => $service->submit($item, $actor, $notes),
            'verify' => $service->verify($item, $actor, $notes),
            'revision' => $service->requestRevision($item, $actor, (string) $notes),
        };
    }

    private function linkWasteHandoverSource(Model $report): void
    {
        if (! $report->source_type || ! $report->source_id) {
            return;
        }
        match ($report->source_type) {
            'washing_session' => WashingSession::query()
                ->where('sppg_unit_id', $report->sppg_unit_id)
                ->whereKey($report->source_id)
                ->update([
                    'waste_handover_report_id' => $report->getKey(),
                    'waste_handed_over_at' => $report->handed_over_at ?: now(),
                ]),
            'cleaning_session' => CleaningSession::query()
                ->where('sppg_unit_id', $report->sppg_unit_id)
                ->whereKey($report->source_id)
                ->update([
                    'waste_handover_report_id' => $report->getKey(),
                    'waste_presence' => 'yes',
                ]),
            'preparation_session' => PreparationSession::query()
                ->where('sppg_unit_id', $report->sppg_unit_id)
                ->whereKey($report->source_id)
                ->update(['waste_handover_report_id' => $report->getKey()]),
            default => null,
        };
    }

    private function unlinkWasteHandoverSource(Model $report): void
    {
        if (! $report->source_type || ! $report->source_id) {
            return;
        }
        match ($report->source_type) {
            'washing_session' => WashingSession::query()
                ->whereKey($report->source_id)
                ->where('waste_handover_report_id', $report->getKey())
                ->update(['waste_handover_report_id' => null, 'waste_handed_over_at' => null]),
            'cleaning_session' => CleaningSession::query()
                ->whereKey($report->source_id)
                ->where('waste_handover_report_id', $report->getKey())
                ->update(['waste_handover_report_id' => null]),
            'preparation_session' => PreparationSession::query()
                ->whereKey($report->source_id)
                ->where('waste_handover_report_id', $report->getKey())
                ->update(['waste_handover_report_id' => null]),
            default => null,
        };
    }

    private function ensureWashingWasteReport(Model $session, $actor): Model
    {
        if (! (bool) $session->has_food_waste || $session->waste_handover_report_id) {
            return $session;
        }
        $session->loadMissing(['wasteRecords', 'sppgUnit']);
        $required = [
            'waste_first_party_name', 'waste_first_party_position', 'waste_first_party_address',
            'waste_second_party_name', 'waste_second_party_position', 'waste_second_party_address',
        ];
        $missing = collect($required)->filter(fn (string $field): bool => blank($session->{$field}));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'waste_handover' => 'Lengkapi identitas pihak penyerah dan penerima limbah.',
            ]);
        }
        if ($session->wasteRecords->isEmpty()) {
            throw ValidationException::withMessages([
                'wasteRecords' => 'Tambahkan minimal satu jenis limbah makanan.',
            ]);
        }

        $report = WasteHandoverReport::create([
            'sppg_unit_id' => $session->sppg_unit_id,
            'division_type' => 'washing',
            'source_type' => 'washing_session',
            'source_id' => $session->getKey(),
            'source_reference' => $session->session_number,
            'report_date' => $session->washing_date,
            'effective_date' => $session->washing_date,
            'handed_over_at' => now(),
            'first_party_name' => $session->waste_first_party_name,
            'first_party_position' => $session->waste_first_party_position,
            'first_party_address' => $session->waste_first_party_address,
            'second_party_name' => $session->waste_second_party_name,
            'second_party_position' => $session->waste_second_party_position,
            'second_party_address' => $session->waste_second_party_address,
            'notes' => $session->waste_handover_notes,
            'petugas_id' => $actor->getKey(),
            'petugas_name_snapshot' => $actor->name,
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
            'source_system' => 'mobile',
        ]);
        foreach ($session->wasteRecords as $index => $record) {
            $report->items()->create([
                'waste_type' => $record->waste_type,
                'quantity' => $record->quantity,
                'unit' => $record->unit,
                'notes' => $record->notes,
                'photo_path' => $record->photo_path,
                'sort_order' => $index + 1,
            ]);
        }
        app(WasteHandoverWorkflow::class)->submit($report, $actor);
        $session->update([
            'waste_handover_report_id' => $report->getKey(),
            'waste_handed_over_at' => $report->handed_over_at,
        ]);

        return $session->refresh();
    }

    private function scalarValue(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }

    /** @return array{0:array<string,mixed>,1:Model,2:array<string,mixed>} */
    private function relationContext(
        Request $request,
        string $module,
        int $record,
        string $relation,
        MobileWorkspaceRegistry $registry,
        SystemUnit $systemUnit,
        string $operation,
    ): array {
        $definition = $registry->authorize($request->user(), $module);
        abort_unless($request->user()->can($definition['permission'].'.update'), 403);
        abort_unless(isset($definition['relations'][$relation]), 404);
        $parent = $this->findRecord($definition, $record, (int) $systemUnit->id());
        $this->assertDistributionRecordAccess($module, $parent, $request->user());
        if (str_starts_with($module, 'pengambilan-gudang-') || $module === 'pengambilan-non-pangan') {
            abort_unless((int) $parent->taken_by === (int) $request->user()->getKey(), 403);
        }
        abort_unless(in_array($operation, $this->relationOperations($module, $relation, $parent), true), 403);
        $relationEditable = $this->isEditable($parent)
            || (in_array($module, ['gudang-pengambilan', 'gudang-pengambilan-non-pangan'], true)
                && $relation === 'items'
                && $this->scalarValue($parent->getAttribute('status')) === WarehouseWithdrawal::WAITING);
        abort_unless($relationEditable, 422, 'Data sudah dikunci dan tidak dapat diubah.');
        abort_unless(method_exists($parent, $relation), 404);

        return [$definition, $parent, $definition['relations'][$relation]];
    }

    /** @return array<int, string> */
    private function relationOperations(string $module, string $relation, ?Model $parent = null): array
    {
        return match ($module) {
            'gudang', 'gudang-non-pangan' => match ($relation) {
                'items' => ['update'],
                'itemPhotos' => ['create', 'delete'],
                default => [],
            },
            'gudang-pengambilan', 'gudang-pengambilan-non-pangan' => $relation === 'items' ? ['update'] : [],
            'pengambilan-gudang-persiapan', 'pengambilan-gudang-pengolahan', 'pengambilan-gudang-pemorsian', 'pengambilan-non-pangan' => $relation === 'items' ? ['create', 'update', 'delete'] : [],
            'lapangan-konfirmasi' => $relation === 'items' ? ['update'] : [],
            'persiapan' => match ($relation) {
                'items' => $this->preparationDataIsEditable($parent) ? ['update'] : [],
                'returns' => $this->preparationDataIsEditable($parent) ? ['create'] : [],
                default => [],
            },
            'pengolahan' => match ($relation) {
                'materialUsages' => $parent instanceof ProcessingBatch && $parent->isOperationalInputEditable() ? ['create', 'update', 'delete'] : [],
                'preparationOutputWithdrawals' => ['action'],
                'temperatureLogs', 'documentations' => $parent instanceof ProcessingBatch
                    && $parent->isOperationalInputEditable()
                        ? ['create', 'update', 'delete'] : [],
                'returns' => $parent instanceof ProcessingBatch
                    && $parent->isOperationalInputEditable()
                        ? ['create'] : [],
                default => [],
            },
            'pemorsian' => match ($relation) {
                'routeAllocations' => $parent?->getAttribute('field_distribution_plan_id')
                    ? ['update'] : ['create', 'update', 'delete'],
                'routeRecords' => ['create', 'update', 'delete'],
                'leftoverRecords' => $parent?->getAttribute('leftover_mode') === 'present'
                    ? ['create', 'update', 'delete'] : [],
                'supplies' => [],
                'preparationOutputWithdrawals' => ['action'],
                default => [],
            },
            'distribusi' => match ($relation) {
                'stops' => $this->scalarValue($parent?->getAttribute('state')) === 'returned'
                    && $this->scalarValue($parent?->getAttribute('status')) === 'revision_required'
                        ? ['update']
                        : (in_array($this->scalarValue($parent?->getAttribute('state')), [
                            'assigned', 'loaded', 'departed',
                        ], true) ? ['action'] : []),
                'incidents' => $parent?->isReportEditable()
                    && in_array($this->scalarValue($parent?->getAttribute('state')), [
                        'assigned', 'loaded', 'departed', 'destinations_completed', 'returned',
                    ], true)
                        ? ['create', 'update', 'delete', 'action'] : [],
                default => [],
            },
            'pencucian' => match ($relation) {
                'checklistItems' => $this->scalarValue($parent?->getAttribute('state')) === 'washing'
                    ? ['action'] : [],
                'wasteRecords' => $this->scalarValue($parent?->getAttribute('state')) === 'received'
                    && (bool) $parent?->getAttribute('has_food_waste')
                        ? ['create', 'update', 'delete'] : [],
                'documentations' => $this->scalarValue($parent?->getAttribute('state')) === 'washing'
                    ? ['create', 'update', 'delete'] : [],
                default => [],
            },
            'kebersihan' => match ($relation) {
                'checklistItems' => ['update'],
                'chemicalUsages', 'documentations', 'findings' => ['create', 'update', 'delete'],
                default => [],
            },
            'keamanan' => $relation === 'reports'
                && $parent?->isReportDue()
                ? ['create']
                : [],
            'ba-limbah-persiapan' => [],
            'ba-limbah-pencucian', 'ba-limbah-kebersihan' => $relation === 'items'
                ? ['create', 'update', 'delete']
                : [],
            default => [],
        };
    }

    /** @param array<string,mixed> $relationDefinition */
    private function validatedRelationValues(Request $request, array $relationDefinition, ?Model $item): array
    {
        $rules = ['fields' => ['present', 'array'], 'files' => ['nullable', 'array']];
        foreach ($relationDefinition['fields'] as $field) {
            $name = $field['name'];
            if (($field['editable'] ?? true) === false) {
                continue;
            }
            if (in_array($name, [
                'id', 'uuid', 'state', 'created_by', 'updated_by',
                'sequence_number', 'due_at', 'reported_at',
                'processing_batch_id', 'portioning_session_id', 'distribution_run_id',
                'preparation_session_id',
            ], true)) {
                continue;
            }
            if (($field['type'] ?? 'text') === 'file') {
                $required = (bool) ($field['required'] ?? false) && blank($item?->getAttribute($name));
                $rules['files.'.$name] = [$required ? 'required' : 'nullable', 'string', 'max:7500000'];

                continue;
            }
            $rule = [($field['required'] ?? false) ? 'required' : 'nullable'];
            $typeRule = match ($field['type'] ?? 'text') {
                'number' => 'numeric',
                'boolean' => 'boolean',
                'date', 'datetime' => 'date',
                'select' => null,
                default => 'string',
            };
            if ($typeRule) {
                $rule[] = $typeRule;
            }
            if (($field['type'] ?? null) === 'select' && isset($field['options']) && is_array($field['options'])) {
                $rule[] = Rule::in(array_keys($field['options']));
            }
            $rules['fields.'.$name] = $rule;
        }
        $validated = $request->validate($rules);
        $allowed = collect($relationDefinition['fields'])
            ->reject(fn (array $field): bool => ($field['type'] ?? 'text') === 'file')
            ->pluck('name');

        return collect($validated['fields'] ?? [])->only($allowed)->map(
            fn ($value) => $value === '' ? null : $value,
        )->all();
    }

    /** @param array<string,mixed> $relationDefinition */
    private function storeRelationFiles(
        Request $request,
        string $module,
        string $relation,
        Model $item,
        array $relationDefinition,
    ): void {
        foreach ($relationDefinition['fields'] as $field) {
            if (($field['type'] ?? null) !== 'file') {
                continue;
            }
            $encoded = $request->input('files.'.$field['name']);
            if (blank($encoded)) {
                continue;
            }
            if (! preg_match('/^data:(image\/(?:jpeg|png|webp));base64,(.+)$/s', $encoded, $matches)) {
                throw ValidationException::withMessages(['files.'.$field['name'] => 'Format foto tidak didukung.']);
            }
            $contents = base64_decode($matches[2], true);
            if ($contents === false || strlen($contents) > 5 * 1024 * 1024 || @getimagesizefromstring($contents) === false) {
                throw ValidationException::withMessages(['files.'.$field['name'] => 'Foto tidak valid atau ukurannya melebihi 5 MB.']);
            }
            $extension = match ($matches[1]) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $path = "mobile/{$module}/{$relation}/".Str::uuid().'.'.$extension;
            Storage::disk('public')->put($path, $contents);
            if ($module === 'persiapan' && $relation === 'items' && $field['name'] === 'result_photo_path') {
                $oldPath = $item->resultDocumentation?->photo_path;
                $item->resultDocumentation()->updateOrCreate([], [
                    'preparation_session_id' => $item->preparation_session_id,
                    'photo_path' => $path, 'captured_at' => now(),
                    'created_by' => $request->user()->getKey(),
                ]);
                if ($oldPath && $oldPath !== $path) Storage::disk('public')->delete($oldPath);
                continue;
            }
            $oldPath = $item->getAttribute($field['name']);
            $item->setAttribute($field['name'], $path);
            if ($item->isFillable('photo_original_name')) {
                $item->setAttribute('photo_original_name', basename($path));
            }
            $item->save();
            if ($oldPath && $oldPath !== $path) {
                Storage::disk('public')->delete($oldPath);
            }
        }
    }

    /** @param array<string,mixed> $definition */
    private function storeRecordFiles(Request $request, string $module, Model $item, array $definition): void
    {
        foreach ($definition['fields'] ?? [] as $field) {
            if (($field['type'] ?? null) !== 'file') {
                continue;
            }
            $encoded = $request->input('files.'.$field['name']);
            if (blank($encoded)) {
                continue;
            }
            $path = $this->storeEncodedImage(
                (string) $encoded,
                "mobile/{$module}/records",
                'files.'.$field['name'],
            );
            if ($module === 'lapangan-insiden' && $field['name'] === 'evidence_photo') {
                $existingPaths = is_array($item->evidence_paths ?? null)
                    ? array_values(array_filter($item->evidence_paths))
                    : [];
                $oldPath = $existingPaths[0] ?? null;
                $item->forceFill([
                    'evidence_paths' => [$path, ...array_slice($existingPaths, 1)],
                ])->save();
                if ($oldPath && $oldPath !== $path) {
                    Storage::disk('public')->delete($oldPath);
                }

                continue;
            }
            $oldPath = $item->getAttribute($field['name']);
            $item->setAttribute($field['name'], $path);
            if ($item->isFillable('photo_original_name')) {
                $item->setAttribute('photo_original_name', basename($path));
            }
            $item->save();
            if ($oldPath && $oldPath !== $path) {
                Storage::disk('public')->delete($oldPath);
            }
        }
    }

    /** @return array<string,mixed> */
    private function applyRelationDefaults(
        string $module,
        string $relation,
        array $values,
        Request $request,
    ): array {
        $defaults = match ([$module, $relation]) {
            ['pengolahan', 'materialUsages'] => [
                'source_type' => 'manual',
                'source_reference' => 'Catatan pemakaian aktual',
                'received_by' => $request->user()->getKey(),
                'received_at' => now(),
            ],
            ['pengolahan', 'temperatureLogs'] => [
                'checkpoint' => 'final',
                'measured_by' => $request->user()->getKey(),
                'measured_name_snapshot' => $request->user()->name,
            ],
            ['pengolahan', 'documentations'] => [
                'documentation_type' => 'finished_output',
                'created_by' => $request->user()->getKey(),
            ],
            ['persiapan', 'resultDocumentation'] => [
                'created_by' => $request->user()->getKey(),
                'captured_at' => now(),
            ],
            ['pemorsian', 'routeRecords'] => [
                'created_by' => $request->user()->getKey(),
                'updated_by' => $request->user()->getKey(),
            ],
            ['pemorsian', 'leftoverRecords'] => [
                'created_by' => $request->user()->getKey(),
                'checked_at' => now(),
            ],
            ['distribusi', 'incidents'] => [
                'occurred_at' => $values['occurred_at'] ?? now(),
                'status' => DistributionIncidentStatus::Open,
            ],
            ['pencucian', 'documentations'] => [
                'phase' => 'after',
                'captured_at' => now(),
                'created_by' => $request->user()->getKey(),
            ],
            ['kebersihan', 'documentations'] => [
                'created_by' => $request->user()->getKey(),
            ],
            ['kebersihan', 'checklistItems'] => in_array($values['result'] ?? null, ['pass', 'fail'], true)
                ? ['checked_at' => now(), 'checked_by' => $request->user()->getKey()]
                : ['checked_at' => null, 'checked_by' => null],
            default => [],
        };

        return $values + $defaults;
    }

    private function recalculateParent(Model $parent): void
    {
        if (method_exists($parent, 'recalculateTotals')) {
            $parent->refresh()->recalculateTotals();
        }
    }

    /** @param array<string,mixed> $relationDefinition */
    private function relationItemData(
        Model $item,
        array $relationDefinition,
        MobileWorkspaceRegistry $registry,
        int $unitId,
    ): array {
        return [
            'id' => $item->getKey(),
            'form_fields' => $this->formFields($relationDefinition, $item, $registry, $unitId, true),
        ];
    }

    /** @param array<string,mixed> $relationDefinition */
    private function storeSecurityReport(
        Request $request,
        Model $shift,
        array $relationDefinition,
        MobileWorkspaceRegistry $registry,
        int $unitId,
    ): JsonResponse {
        $values = $this->validatedRelationValues($request, $relationDefinition, null);
        $path = $this->storeEncodedImage(
            (string) $request->input('files.photo_path'),
            'mobile/keamanan/reports',
            'files.photo_path',
        );
        try {
            $report = app(SecurityMonitoringService::class)->submitReport(
                $shift,
                $request->user(),
                [...$values, 'photo_path' => $path],
            );
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        return response()->json([
            'message' => 'Laporan keamanan berhasil disimpan.',
            'data' => $this->relationItemData($report, $relationDefinition, $registry, $unitId),
        ], 201);
    }

    private function storeEncodedImage(string $encoded, string $directory, string $errorKey = 'files.photo_path'): string
    {
        if (! preg_match('/^data:(image\/(?:jpeg|png|webp));base64,(.+)$/s', $encoded, $matches)) {
            throw ValidationException::withMessages([$errorKey => 'Format foto tidak didukung.']);
        }
        $contents = base64_decode($matches[2], true);
        if ($contents === false || strlen($contents) > 5 * 1024 * 1024 || @getimagesizefromstring($contents) === false) {
            throw ValidationException::withMessages([$errorKey => 'Foto tidak valid atau ukurannya melebihi 5 MB.']);
        }
        $extension = match ($matches[1]) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($path, $contents);

        return $path;
    }

    /** @param iterable<int,array<string,mixed>> $sections */
    private function enrichSections(
        iterable $sections,
        string $module,
        array $definition,
        Model $parent,
        MobileWorkspaceRegistry $registry,
        int $unitId,
        bool $editable,
        Request $request,
    ): array {
        return collect($sections)->map(function (array $section) use (
            $module, $definition, $parent, $registry, $unitId, $editable, $request,
        ): array {
            $key = $section['key'];
            $operations = $this->relationOperations($module, $key, $parent);
            $relationDefinition = $definition['relations'][$key];
            $related = $parent->getRelation($key);
            $models = $related instanceof Collection
                ? $related->keyBy(fn (Model $item) => $item->getKey())
                : collect($related ? [$related->getKey() => $related] : []);
            $section['can_create'] = $editable && in_array('create', $operations, true);
            $emptyFormFields = $this->formFields(
                $relationDefinition,
                $parent->{$key}()->getRelated(),
                $registry,
                $unitId,
                true,
            );
            if (in_array($module, ['gudang', 'gudang-non-pangan'], true) && $key === 'itemPhotos') {
                $receiptItemOptions = $parent->items
                    ->mapWithKeys(fn (Model $item): array => [
                        (string) $item->getKey() => trim(implode(' · ', array_filter([
                            $item->getAttribute('ingredient_name_snapshot'),
                            filled($item->getAttribute('received_quantity'))
                                ? $item->getAttribute('received_quantity').' '.$item->getAttribute('unit_snapshot')
                                : null,
                        ]))),
                    ])->all();
                $emptyFormFields = collect($emptyFormFields)->map(function (array $field) use ($receiptItemOptions): array {
                    if ($field['key'] === 'stock_receipt_item_id') {
                        $field['options'] = $receiptItemOptions;
                    }

                    return $field;
                })->values()->all();
            }
            if ($module === 'persiapan' && $key === 'returns') {
                $itemOptions = $parent->items
                    ->mapWithKeys(fn (Model $item): array => [
                        (string) $item->getKey() => trim(implode(' · ', array_filter([
                            $item->getAttribute('ingredient_name_snapshot'),
                            filled($item->getAttribute('received_quantity'))
                                ? 'diterima '.$item->getAttribute('received_quantity').' '.$item->getAttribute('unit_snapshot')
                                : null,
                        ]))),
                    ])->all();
                $emptyFormFields = collect($emptyFormFields)->map(function (array $field) use ($itemOptions): array {
                    if ($field['key'] === 'preparation_session_item_id') {
                        $field['options'] = $itemOptions;
                    }

                    return $field;
                })->values()->all();
            }
            if ($module === 'pengolahan' && $key === 'returns') {
                $usageOptions = $parent->materialUsages
                    ->mapWithKeys(function (Model $usage): array {
                        $returned = $usage->returns()
                            ->whereIn('status', [ProcessingReturn::WAITING, ProcessingReturn::VERIFIED])
                            ->sum(DB::raw('COALESCE(actual_quantity, requested_quantity)'));
                        $remaining = max(0, (float) $usage->quantity - (float) $returned);

                        return $remaining > 0.0001 ? [(string) $usage->getKey() => sprintf(
                            '%s · sisa %s %s',
                            $usage->material_name,
                            rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.'),
                            $usage->unit_name,
                        )] : [];
                    })->all();
                $emptyFormFields = collect($emptyFormFields)->map(function (array $field) use ($usageOptions): array {
                    if ($field['key'] === 'processing_material_usage_id') {
                        $field['options'] = $usageOptions;
                    }

                    return $field;
                })->values()->all();
            }
            if ($module === 'distribusi' && $key === 'incidents') {
                $stopOptions = $parent->stops
                    ->mapWithKeys(fn (Model $stop): array => [
                        (string) $stop->getKey() => $stop->destination_name.' · '.$stop->route_name,
                    ])->all();
                $emptyFormFields = collect($emptyFormFields)->map(function (array $field) use ($stopOptions): array {
                    if ($field['key'] === 'distribution_stop_id') {
                        $field['options'] = $stopOptions;
                    }

                    return $field;
                })->values()->all();
            }
            $section['empty_form_fields'] = $emptyFormFields;
            $section['items'] = collect($section['items'])->map(function (array $item) use (
                $models, $relationDefinition, $registry, $unitId, $editable, $operations,
                $module, $parent, $key, $request,
            ): array {
                $model = $models->get($item['id']);
                if (! $model) {
                    return $item;
                }
                $itemRelationDefinition = $relationDefinition;
                if ($module === 'distribusi' && $key === 'stops'
                    && $this->scalarValue($parent->getAttribute('state')) === 'returned'
                    && $this->scalarValue($parent->getAttribute('status')) === 'revision_required') {
                    $itemRelationDefinition['fields'] = collect($itemRelationDefinition['fields'])
                        ->map(function (array $field): array {
                            if ($field['name'] === 'status') {
                                $field['editable'] = true;
                                $field['options'] = [
                                    'delivered' => 'Selesai',
                                    'partial' => 'Terkirim Sebagian',
                                    'failed' => 'Gagal Dikirim',
                                ];
                            }

                            return $field;
                        })->all();
                }
                $item['form_fields'] = $this->formFields($itemRelationDefinition, $model, $registry, $unitId, true);
                $item['can_update'] = $editable && in_array('update', $operations, true);
                $item['can_delete'] = $editable && in_array('delete', $operations, true);
                if ($module === 'pengolahan' && $key === 'materialUsages'
                    && $model->getAttribute('source_type') !== 'manual') {
                    $item['can_update'] = false;
                    $item['can_delete'] = false;
                }
                $item['actions'] = $this->relationActions(
                    $request,
                    $module,
                    $parent,
                    $key,
                    $model,
                );

                return $item;
            })->values()->all();

            return $section;
        })->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function relationActions(
        Request $request,
        string $module,
        Model $parent,
        string $relation,
        Model $item,
    ): array {
        if (in_array($module, ['pengolahan', 'pemorsian'], true)
            && $relation === 'preparationOutputWithdrawals'
            && $this->scalarValue($item->getAttribute('status')) === PreparationOutputWithdrawal::WAITING
            && $request->user()?->can(($module === 'pengolahan' ? 'processing' : 'portioning').'.update')) {
            return [$this->actionDefinition('accept', 'Terima hasil Persiapan')];
        }

        if ($module === 'pencucian'
            && $relation === 'checklistItems'
            && $request->user()?->can('washing.update')
            && $this->scalarValue($parent->getAttribute('state')) === 'washing') {
            $passed = (bool) $item->getAttribute('is_passed');

            return [$this->actionDefinition(
                $passed ? 'uncheck' : 'check',
                $passed ? 'Buka kembali checklist' : 'Tandai selesai',
                false,
                $passed ? [] : [
                    $this->actionField('notes', 'Catatan pemeriksaan', 'textarea', false, (string) $item->getAttribute('notes')),
                ],
            )];
        }

        if ($module !== 'distribusi' || ! $request->user()?->can('distribution.update')) {
            return [];
        }

        if (! $request->user()->can('distribution.approve')
            && (int) $parent->getAttribute('petugas_id') !== (int) $request->user()->getKey()) {
            return [];
        }

        if ($relation === 'incidents') {
            $status = $this->scalarValue($item->getAttribute('status'));

            return in_array($status, ['open', 'in_progress'], true)
                ? [$this->actionDefinition('resolve', 'Tandai insiden selesai')]
                : [];
        }

        if ($relation !== 'stops') {
            return [];
        }

        $runState = $this->scalarValue($parent->getAttribute('state'));
        if (in_array($runState, ['assigned', 'loaded'], true)) {
            $minimum = (int) $parent->stops()->min('sequence_order');
            $maximum = (int) $parent->stops()->max('sequence_order');
            $sequence = (int) $item->getAttribute('sequence_order');

            return array_values(array_filter([
                $sequence > $minimum
                    ? $this->actionDefinition('move_up', 'Naikkan urutan') : null,
                $sequence < $maximum
                    ? $this->actionDefinition('move_down', 'Turunkan urutan') : null,
            ]));
        }

        if ($runState !== 'departed') {
            return [];
        }

        $failureFields = [
            $this->actionField('failure_reason', 'Alasan gagal dikirim', 'textarea', true),
            $this->actionField('handover_photo_path', 'Foto kondisi di tujuan', 'file'),
        ];

        return match ($this->scalarValue($item->getAttribute('status'))) {
            'planned', 'in_transit' => [
                $this->actionDefinition('arrive', 'Makanan tiba di tujuan'),
                $this->actionDefinition('fail', 'Tidak dapat dikirim', false, $failureFields),
            ],
            'arrived' => [
                $this->actionDefinition('deliver', 'Simpan serah-terima', false, [
                    $this->actionField('delivered_small_portions', 'Porsi kecil diserahkan', 'number', true, (string) $item->getAttribute('small_portions')),
                    $this->actionField('delivered_large_portions', 'Porsi besar diserahkan', 'number', true, (string) $item->getAttribute('large_portions')),
                    $this->actionField('containers_sent', 'Ompreng/wadah diserahkan', 'number', true, (string) ((int) $item->getAttribute('small_portions') + (int) $item->getAttribute('large_portions'))),
                    $this->actionField('recipient_name', 'Nama penerima', 'text', true, (string) $item->getAttribute('recipient_name')),
                    $this->actionField('recipient_position', 'Jabatan penerima', 'text', false, (string) $item->getAttribute('recipient_position')),
                    $this->actionField('handover_photo_path', 'Foto serah-terima', 'file', blank($item->getAttribute('handover_photo_path'))),
                    $this->actionField('failure_reason', 'Alasan jika jumlah tidak sesuai', 'textarea', false, (string) $item->getAttribute('failure_reason')),
                ]),
                $this->actionDefinition('fail', 'Tidak dapat dikirim', false, $failureFields),
            ],
            default => [],
        };
    }

    private function detailWithRelations(
        Request $request,
        string $module,
        array $definition,
        Model $item,
        MobileWorkspaceRegistry $registry,
        MobileOperationalRecordTransformer $transformer,
        int $unitId,
    ): array {
        $detail = $transformer->detail($module, $definition, $item, $unitId);
        $detail['sections'] = $this->enrichSections(
            $detail['sections'],
            $module,
            $definition,
            $item,
            $registry,
            $unitId,
            $this->isEditable($item) && $request->user()->can($definition['permission'].'.update'),
            $request,
        );
        $detail['form_fields'] = $this->formFields($definition, $item, $registry, $unitId);

        return $detail;
    }

    private function formValue(mixed $value, string $type): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $type === 'date'
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d\TH:i');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value === null ? null : (string) $value;
    }

    private function isEditable(Model $item): bool
    {
        if (method_exists($item, 'isReportEditable')) {
            return $item->isReportEditable();
        }
        if (method_exists($item, 'isEditable')) {
            return $item->isEditable();
        }

        return true;
    }

    private function preparationDataIsEditable(?Model $item): bool
    {
        if (! $item instanceof PreparationSession || ! $item->isReportEditable()) {
            return false;
        }

        return $item->state === 'in_progress'
            || ($item->state === 'completed'
                && $item->status === OperationalReportStatus::RevisionRequired);
    }

    private function isDeletable(Model $item): bool
    {
        if (! $this->isEditable($item)) {
            return false;
        }

        $state = $this->scalarValue($item->getAttribute('state'));
        if ($state !== null) {
            return $state === 'planned';
        }

        return $this->scalarValue($item->getAttribute('status')) === 'draft';
    }

    /** @param array<string, mixed> $definition */
    private function loadRelations(Model $item, array $definition): Model
    {
        $relations = array_values(array_filter(
            array_keys($definition['relations'] ?? []),
            fn (string $relation): bool => method_exists($item, $relation),
        ));

        return $relations === [] ? $item : $item->load($relations);
    }
}
