<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Mobile\MobileOperationalRecordTransformer;
use App\Support\Mobile\MobileWorkspaceRegistry;
use App\Support\V3\SystemUnit;
use App\Services\V3\OperationalRecordInitializer;
use App\Services\SecurityMonitoringService;
use App\Services\PreparationSessionService;
use App\Services\ProcessingWorkflow;
use App\Services\PortioningWorkflow;
use App\Services\DistributionWorkflow;
use App\Services\WashingWorkflow;
use App\Services\CleaningWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Relations\HasOne;
use DateTimeInterface;
use DomainException;
use RuntimeException;

class MobileOperationalController extends Controller
{
    public function modules(
        Request $request,
        MobileWorkspaceRegistry $registry,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $modules = collect($registry->forUser($request->user()))
            ->map(function (array $definition, string $slug) use ($request, $registry, $systemUnit): array {
                $model = $definition['model'];
                $emptyRecord = new $model;

                $query = $model::query()->where('sppg_unit_id', $systemUnit->id());
                $this->applyDefinitionScope($query, $definition);

                return [
                    'slug' => $slug,
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'permission' => $definition['permission'],
                    'record_count' => $query->count(),
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

        return response()->json(['data' => $modules]);
    }

    public function index(
        Request $request,
        string $module,
        MobileWorkspaceRegistry $registry,
        MobileOperationalRecordTransformer $transformer,
        SystemUnit $systemUnit,
    ): JsonResponse {
        $definition = $registry->authorize($request->user(), $module);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $model = $definition['model'];
        $query = $model::query()
            ->where('sppg_unit_id', $systemUnit->id())
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where($definition['number'], 'like', "%{$search}%"))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query
                ->whereDate($definition['date'], '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query
                ->whereDate($definition['date'], '<=', $date));
        $this->applyDefinitionScope($query, $definition);

        $this->addSummaryCounts($query, $module);
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
                    $records = app(\App\Services\MobileDailyBeneficiaryConfirmationService::class)
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

                    return app(\App\Services\WarehouseWithdrawalService::class)->createMobileDraft(
                        $unitId,
                        $division,
                        (string) $fields['reference_selection'],
                        $fields['purpose_reference'] ?? null,
                        $fields['shift'] ?? null,
                        $fields['notes'] ?? null,
                        $request->user(),
                    );
                }

                if ($module === 'lapangan-laporan') {
                    $fields = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.report_date' => ['required', 'date'],
                    ])['fields'];

                    return app(\App\Services\FieldDailyReportGenerator::class)->generate(
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

                if ($module === 'pengambilan-ompreng') {
                    $fields = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.kernet_name' => ['nullable', 'string', 'max:150'],
                        'fields.vehicle_name' => ['nullable', 'string', 'max:150'],
                        'fields.vehicle_plate' => ['nullable', 'string', 'max:30'],
                        'fields.notes' => ['nullable', 'string', 'max:2000'],
                    ])['fields'];

                    return app(\App\Services\ContainerCollectionWorkflow::class)
                        ->startRun($unitId, $request->user(), $fields);
                }

                if ($module === 'hasil-persiapan') {
                    $input = $request->validate([
                        'fields' => ['present', 'array'],
                        'fields.preparation_session_item_id' => ['required', 'integer'],
                        'fields.output_name' => ['required', 'string', 'max:180'],
                        'fields.quantity' => ['required', 'numeric', 'gt:0'],
                        'fields.unit_snapshot' => ['required', 'string', 'max:30'],
                        'fields.target_division' => ['required', Rule::in(['processing', 'portioning', 'both'])],
                        'fields.storage_location' => ['nullable', 'string', 'max:180'],
                        'fields.stored_at' => ['nullable', 'date'],
                        'fields.expires_at' => ['nullable', 'date', 'after:fields.stored_at'],
                        'fields.notes' => ['nullable', 'string', 'max:2000'],
                        'files.photo_path' => ['nullable', 'string', 'max:7500000'],
                    ]);
                    $sourceItem = \App\Models\PreparationSessionItem::query()
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
                        return app(\App\Services\PreparationOutputService::class)->store(
                            $session,
                            $sourceItem,
                            $request->user(),
                            [...$input['fields'], 'photo_path' => $photoPath],
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
                    $values = app(\App\Services\WasteHandoverWorkflow::class)
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
                app(\App\Services\FieldDailyReportWorkflow::class)->update(
                    $item,
                    $request->user(),
                    $values,
                );
            } else {
                if (str_starts_with($module, 'ba-limbah-')) {
                    $this->unlinkWasteHandoverSource($item);
                    $values = app(\App\Services\WasteHandoverWorkflow::class)
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
                    'gudang' => $this->runWarehouseReceiptAction($action, $item, $actor),
                    'gudang-stok' => $this->runWarehouseStockAction($action, $item, $actor, $fields, $notes),
                    'gudang-pengambilan' => $this->runWarehouseWithdrawalAction($action, $item, $actor, $notes),
                    'pengambilan-gudang-persiapan', 'pengambilan-gudang-pengolahan', 'pengambilan-gudang-pemorsian' =>
                        $this->runDivisionWarehouseWithdrawalAction($action, $item, $actor),
                    'gudang-retur' => $this->runPreparationReturnAction($action, $item, $actor, $fields, $notes),
                    'gudang-retur-pengolahan' => $this->runProcessingReturnAction($action, $item, $actor, $fields, $notes),
                    'lapangan-konfirmasi' => $this->runDailyBeneficiaryConfirmationAction($action, $item, $actor),
                    'persiapan' => $this->runPreparationAction($action, $item, $actor, $notes),
                    'hasil-persiapan', 'hasil-persiapan-pengolahan', 'hasil-persiapan-pemorsian' =>
                        $this->runPreparationOutputAction($module, $action, $item, $actor, $fields, $notes),
                    'pengolahan' => $this->runStandardAction(app(ProcessingWorkflow::class), $action, $item, $actor, $notes),
                    'pemorsian' => $this->runStandardAction(app(PortioningWorkflow::class), $action, $item, $actor, $notes),
                    'distribusi' => $this->runDistributionAction($action, $item, $actor, $data, $notes),
                    'pengambilan-ompreng' => $this->runContainerCollectionAction(
                        $action, $item, $actor, $fields, $notes, $storedPhoto,
                    ),
                    'pencucian' => $this->runWashingAction($action, $item, $actor, $data, $notes),
                    'kebersihan' => $this->runCleaningAction($action, $item, $actor, $data, $notes),
                    'ba-limbah-persiapan', 'ba-limbah-pencucian', 'ba-limbah-kebersihan' =>
                        $this->runWasteHandoverAction($action, $item, $actor, $notes),
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
                $return = app(\App\Services\PreparationReturnService::class)->submit(
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
                $return = app(\App\Services\ProcessingReturnService::class)->submit(
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
        abort_unless($module === 'distribusi' && in_array($relation, ['stops', 'incidents'], true), 404);
        [, $parent] = $this->relationContext(
            $request, $module, $record, $relation, $registry, $systemUnit, 'update',
        );
        $child = $parent->{$relation}()->whereKey($item)->firstOrFail();
        $available = collect($this->relationActions($request, $module, $parent, $relation, $child))->pluck('key');
        abort_unless($available->contains($action), 422, 'Tindakan rincian tidak tersedia.');

        if ($relation === 'incidents') {
            abort_unless($action === 'resolve', 422);
            $child->forceFill([
                'status' => \App\Enums\DistributionIncidentStatus::Resolved,
                'resolved_at' => now(),
                'resolved_by' => $request->user()->getKey(),
            ])->save();

            return response()->json(['message' => 'Insiden distribusi ditandai selesai.']);
        }

        $workflow = app(DistributionWorkflow::class);
        $data = $child->getAttributes();
        match ($action) {
            'arrive' => $workflow->arriveAtStop($parent, $child, $request->user()),
            'deliver' => $workflow->completeStop($parent, $child, $request->user(), $data),
            'fail' => $workflow->failStop($parent, $child, $request->user(), $data),
        };

        return response()->json(['message' => 'Status tujuan berhasil diperbarui.']);
    }

    /** @param array<string, mixed> $definition */
    private function applyDefinitionScope(Builder $query, array $definition): void
    {
        foreach ($definition['where'] ?? [] as $column => $value) {
            $query->where($column, $value);
        }

        foreach ($definition['where_in'] ?? [] as $column => $values) {
            $query->whereIn($column, (array) $values);
        }
    }

    private function addSummaryCounts(Builder $query, string $module): void
    {
        $relations = match ($module) {
            'gudang' => ['items'],
            'gudang-stok' => ['movements'],
            'gudang-pengambilan', 'pengambilan-gudang-persiapan', 'pengambilan-gudang-pengolahan', 'pengambilan-gudang-pemorsian' => ['items'],
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

        return collect($validated['fields'])
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
    ): array
    {
        return collect($definition['fields'] ?? [])->map(function (array $field) use ($item, $registry, $unitId, $relationMode): array {
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
                && $this->canViewDocument($request, $module, $definition),
        ];
    }

    /** @param array<string,mixed> $definition */
    private function canViewDocument(Request $request, string $module, array $definition): bool
    {
        if (! in_array($module, [
            'lapangan-laporan', 'pengolahan', 'pemorsian', 'distribusi',
            'pencucian', 'kebersihan', 'ba-limbah-persiapan',
            'ba-limbah-pencucian', 'ba-limbah-kebersihan',
        ], true)) {
            return false;
        }

        if (str_starts_with($module, 'ba-limbah-')) {
            return $request->user()->can($definition['permission'].'.view');
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

        if ($module === 'gudang' && $status === \App\Models\StockReceipt::STATUS_DRAFT
            && $actor->can('stock.update')) {
            return [$this->actionDefinition('receive', 'Selesaikan penerimaan dan bentuk stok')];
        }

        if ($module === 'gudang-stok' && $actor->can('stock.update')) {
            return [
                $this->actionDefinition('adjust_stock', 'Sesuaikan dengan stok fisik', false, [
                    $this->actionField(
                        'actual_quantity',
                        'Jumlah fisik aktual',
                        'number',
                        true,
                        (string) $item->balance_quantity,
                    ),
                    $this->actionField('adjustment_type', 'Jenis penyesuaian', 'select', true, 'physical_count', [
                        'physical_count' => 'Stok opname',
                        'damage' => 'Rusak',
                        'expired' => 'Kedaluwarsa',
                        'correction' => 'Koreksi pencatatan',
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

        if ($module === 'lapangan-konfirmasi'
            && $item->isEditable()
            && $actor->can('daily_beneficiary_confirmations.submit')) {
            return [$this->actionDefinition('confirm', 'Konfirmasi dan sinkronkan rencana distribusi')];
        }

        if (str_starts_with($module, 'pengambilan-gudang-')
            && in_array($status, [\App\Models\WarehouseWithdrawal::DRAFT, \App\Models\WarehouseWithdrawal::REVISION], true)
            && (int) $item->taken_by === (int) $actor->getKey()) {
            return [$this->actionDefinition('submit', 'Ajukan untuk verifikasi Gudang')];
        }

        if ($module === 'gudang-pengambilan'
            && $status === \App\Models\WarehouseWithdrawal::WAITING
            && $actor->can('stock.approve')) {
            return [
                $this->actionDefinition('verify', 'Verifikasi pengambilan'),
                $this->actionDefinition('revision', 'Minta koreksi', true),
                $this->actionDefinition('reject', 'Tolak pengambilan', true),
            ];
        }

        if ($module === 'gudang-retur'
            && $status === \App\Models\PreparationReturn::WAITING
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
            && $status === \App\Models\ProcessingReturn::WAITING
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
                ? \App\Models\ProcessingBatch::query()
                    ->where('sppg_unit_id', $item->sppg_unit_id)
                    ->whereIn('state', ['planned', 'in_progress'])
                    ->latest('production_date')->limit(100)->pluck('batch_number', 'id')->mapWithKeys(
                        fn ($label, $id): array => [(string) $id => (string) $label],
                    )->all()
                : \App\Models\PortioningSession::query()
                    ->where('sppg_unit_id', $item->sppg_unit_id)
                    ->whereIn('state', ['planned', 'in_progress'])
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
                ->where('status', \App\Models\PreparationOutputWithdrawal::WAITING)
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

        if ($module === 'pengambilan-ompreng' && $state === \App\Models\ContainerCollectionRun::ACTIVE
            && $actor->can('distribution.update')) {
            $taskOptions = \App\Models\ContainerCollectionTask::query()
                ->where('sppg_unit_id', $item->sppg_unit_id)
                ->whereIn('status', [
                    \App\Models\ContainerCollectionTask::PENDING,
                    \App\Models\ContainerCollectionTask::PARTIAL,
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
                    'in_progress' => [$this->actionDefinition('complete', 'Selesaikan pekerjaan')],
                    default => [],
                },
                'distribusi' => match ($state) {
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
                    'received' => $item->wasteHandlingCompleted()
                        ? [$this->actionDefinition('start', 'Mulai pencucian')]
                        : [$this->actionDefinition('waste', 'Simpan pencatatan limbah', false, [
                            $this->actionField('has_food_waste', 'Terdapat limbah makanan', 'boolean', true, $item->has_food_waste === null ? null : ((bool) $item->has_food_waste ? '1' : '0')),
                            $this->actionField('no_waste_confirmed', 'Konfirmasi tidak ada limbah', 'boolean', false, (bool) $item->no_waste_confirmed ? '1' : '0'),
                            $this->actionField('waste_first_party_name', 'Nama pihak penyerah', 'text', false, (string) $item->waste_first_party_name),
                            $this->actionField('waste_first_party_position', 'Jabatan pihak penyerah', 'text', false, (string) $item->waste_first_party_position),
                            $this->actionField('waste_first_party_address', 'Alamat pihak penyerah', 'textarea', false, (string) $item->waste_first_party_address),
                            $this->actionField('waste_second_party_name', 'Nama penerima limbah', 'text', false, (string) $item->waste_second_party_name),
                            $this->actionField('waste_second_party_position', 'Jabatan penerima limbah', 'text', false, (string) $item->waste_second_party_position),
                            $this->actionField('waste_second_party_address', 'Alamat penerima limbah', 'textarea', false, (string) $item->waste_second_party_address),
                            $this->actionField('waste_handover_notes', 'Catatan serah-terima', 'textarea', false, (string) $item->waste_handover_notes),
                        ])],
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
        }

        if (in_array($state, ['completed', 'ready', 'returned'], true)
            && in_array($status, ['draft', 'revision_required'], true)
            && $actor->can($permission.'.submit')) {
            $actions[] = $this->actionDefinition('submit', 'Ajukan laporan');
        }
        if (str_starts_with($module, 'ba-limbah-')
            && in_array($status, ['draft', 'revision_required'], true)
            && $actor->can($permission.'.submit')) {
            $actions[] = $this->actionDefinition('submit', 'Ajukan berita acara');
        }
        if (in_array($status, ['submitted', 'division_approved'], true)
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
        $service = app(\App\Services\StockControlService::class);

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
        if (! in_array($type, ['physical_count', 'damage', 'expired', 'correction'], true)) {
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

        $adjustment = $service->create($item, (float) $actual, $type, $reason, $actor);
        $service->verify($adjustment, $actor);

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

        return app(\App\Services\StockReceiptService::class)->receive($item);
    }

    private function runDivisionWarehouseWithdrawalAction(
        string $action,
        Model $item,
        $actor,
    ): Model {
        abort_unless($action === 'submit', 422);

        return app(\App\Services\WarehouseWithdrawalService::class)
            ->submitMobileDraft($item, $actor);
    }

    private function runDailyBeneficiaryConfirmationAction(
        string $action,
        Model $item,
        $actor,
    ): Model {
        abort_unless($action === 'confirm', 422);

        return app(\App\Services\MobileDailyBeneficiaryConfirmationService::class)
            ->confirm($item, $actor);
    }

    private function runWarehouseWithdrawalAction(
        string $action,
        Model $item,
        $actor,
        ?string $notes,
    ): Model {
        $service = app(\App\Services\WarehouseWithdrawalService::class);

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
        $service = app(\App\Services\PreparationReturnService::class);

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
        $service = app(\App\Services\ProcessingReturnService::class);

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
        $service = app(\App\Services\PreparationOutputService::class);
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
        $service = app(\App\Services\ContainerCollectionWorkflow::class);
        if ($action === 'return') {
            return $service->returnToSppg($item, $actor);
        }

        $task = \App\Models\ContainerCollectionTask::query()
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
        $service = app(\App\Services\FieldDailyReportWorkflow::class);
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
                'status' => \App\Enums\FieldIncidentStatus::InProgress,
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
            'status' => \App\Enums\FieldIncidentStatus::Resolved,
            'resolution' => $resolution,
            'root_cause' => trim((string) ($fields['root_cause'] ?? '')) ?: $item->root_cause,
            'immediate_action' => trim((string) ($fields['immediate_action'] ?? '')) ?: $item->immediate_action,
            'resolved_at' => now(),
            'resolved_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        return $item->refresh();
    }

    private function runPreparationAction(string $action, Model $item, $actor, ?string $notes): Model
    {
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

    private function runStandardAction(object $service, string $action, Model $item, $actor, ?string $notes): Model
    {
        return match ($action) {
            'start', 'complete' => $service->{$action}($item, $actor),
            'submit' => $service->submit($item, $actor, $notes),
            'verify' => $service->verify($item, $actor, $notes),
            'revision' => $service->requestRevision($item, $actor, (string) $notes),
        };
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
            'waste' => $service->recordWaste(
                $this->ensureWashingWasteReport($item, $actor),
                $actor,
                $data,
            ),
            'start' => $service->start($item, $actor, $data),
            'complete' => $service->complete($item, $actor, $data),
            'ready' => $service->markReady($item, $actor, $notes),
            'submit' => $service->submit($item, $actor, $notes),
            'verify' => $service->verify($item, $actor, $notes),
            'revision' => $service->requestRevision($item, $actor, (string) $notes),
        };
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
        $service = app(\App\Services\WasteHandoverWorkflow::class);

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
            'washing_session' => \App\Models\WashingSession::query()
                ->where('sppg_unit_id', $report->sppg_unit_id)
                ->whereKey($report->source_id)
                ->update([
                    'waste_handover_report_id' => $report->getKey(),
                    'waste_handed_over_at' => $report->handed_over_at ?: now(),
                ]),
            'cleaning_session' => \App\Models\CleaningSession::query()
                ->where('sppg_unit_id', $report->sppg_unit_id)
                ->whereKey($report->source_id)
                ->update([
                    'waste_handover_report_id' => $report->getKey(),
                    'waste_presence' => 'yes',
                ]),
            'preparation_session' => \App\Models\PreparationSession::query()
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
            'washing_session' => \App\Models\WashingSession::query()
                ->whereKey($report->source_id)
                ->where('waste_handover_report_id', $report->getKey())
                ->update(['waste_handover_report_id' => null, 'waste_handed_over_at' => null]),
            'cleaning_session' => \App\Models\CleaningSession::query()
                ->whereKey($report->source_id)
                ->where('waste_handover_report_id', $report->getKey())
                ->update(['waste_handover_report_id' => null]),
            'preparation_session' => \App\Models\PreparationSession::query()
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

        $report = \App\Models\WasteHandoverReport::create([
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
        app(\App\Services\WasteHandoverWorkflow::class)->submit($report, $actor);
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
        if (str_starts_with($module, 'pengambilan-gudang-')) {
            abort_unless((int) $parent->taken_by === (int) $request->user()->getKey(), 403);
        }
        abort_unless(in_array($operation, $this->relationOperations($module, $relation, $parent), true), 403);
        $relationEditable = $this->isEditable($parent)
            || ($module === 'gudang-pengambilan'
                && $relation === 'items'
                && $this->scalarValue($parent->getAttribute('status')) === \App\Models\WarehouseWithdrawal::WAITING);
        abort_unless($relationEditable, 422, 'Data sudah dikunci dan tidak dapat diubah.');
        abort_unless(method_exists($parent, $relation), 404);

        return [$definition, $parent, $definition['relations'][$relation]];
    }

    /** @return array<int, string> */
    private function relationOperations(string $module, string $relation, ?Model $parent = null): array
    {
        return match ($module) {
            'gudang' => $relation === 'items' ? ['update'] : [],
            'gudang-pengambilan' => $relation === 'items' ? ['update'] : [],
            'pengambilan-gudang-persiapan', 'pengambilan-gudang-pengolahan', 'pengambilan-gudang-pemorsian' =>
                $relation === 'items' ? ['create', 'update', 'delete'] : [],
            'lapangan-konfirmasi' => $relation === 'items' ? ['update'] : [],
            'persiapan' => match ($relation) {
                'items' => ['update'],
                'resultDocumentation' => ['create', 'update', 'delete'],
                'returns' => ['create'],
                default => [],
            },
            'pengolahan' => match ($relation) {
                'materialUsages', 'temperatureLogs', 'documentations' => ['create', 'update', 'delete'],
                'returns' => ['create'],
                default => [],
            },
            'pemorsian' => match ($relation) {
                'routeAllocations' => $parent?->getAttribute('field_distribution_plan_id')
                    ? ['update'] : ['create', 'update', 'delete'],
                'routeRecords', 'leftoverRecords', 'supplies' => ['create', 'update', 'delete'],
                default => [],
            },
            'distribusi' => match ($relation) {
                'stops' => $parent?->getAttribute('portioning_session_id')
                    ? ['update'] : ['create', 'update', 'delete'],
                'incidents' => ['create', 'update', 'delete'],
                default => [],
            },
            'pencucian' => match ($relation) {
                'checklistItems' => ['update'],
                'wasteRecords', 'documentations' => ['create', 'update', 'delete'],
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
            'ba-limbah-persiapan', 'ba-limbah-pencucian', 'ba-limbah-kebersihan' => $relation === 'items'
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

        return collect($validated['fields'])->only($allowed)->map(
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
            ['pengolahan', 'temperatureLogs'] => [
                'checkpoint' => 'final',
                'measured_by' => $request->user()->getKey(),
                'measured_name_snapshot' => $request->user()->name,
            ],
            ['pengolahan', 'documentations'] => [
                'documentation_type' => 'finished_output',
                'created_by' => $request->user()->getKey(),
            ],
            ['persiapan', 'resultDocumentation'] => ['created_by' => $request->user()->getKey()],
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
                'status' => \App\Enums\DistributionIncidentStatus::Open,
            ],
            ['pencucian', 'documentations'], ['kebersihan', 'documentations'] => [
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
            $models = $related instanceof \Illuminate\Support\Collection
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
                            ->whereIn('status', [\App\Models\ProcessingReturn::WAITING, \App\Models\ProcessingReturn::VERIFIED])
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

    /** @return array<int,array{key:string,label:string}> */
    private function relationActions(
        Request $request,
        string $module,
        Model $parent,
        string $relation,
        Model $item,
    ): array {
        if ($module !== 'distribusi' || ! $request->user()?->can('distribution.update')) {
            return [];
        }

        if ($relation === 'incidents') {
            $status = $this->scalarValue($item->getAttribute('status'));

            return in_array($status, ['open', 'in_progress'], true)
                ? [['key' => 'resolve', 'label' => 'Tandai insiden selesai']]
                : [];
        }

        if ($relation !== 'stops' || $this->scalarValue($parent->getAttribute('state')) !== 'departed') {
            return [];
        }

        return match ($this->scalarValue($item->getAttribute('status'))) {
            'planned', 'in_transit' => [
                ['key' => 'arrive', 'label' => 'Tandai tiba'],
                ['key' => 'fail', 'label' => 'Tandai gagal'],
            ],
            'arrived' => [
                ['key' => 'deliver', 'label' => 'Selesaikan pengiriman'],
                ['key' => 'fail', 'label' => 'Tandai gagal'],
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
