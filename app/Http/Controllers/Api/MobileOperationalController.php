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
                    'can_create' => $slug !== 'persiapan'
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
        abort_unless($request->user()->can($definition['permission'].'.create'), 403);
        abort_if(
            $module === 'persiapan',
            422,
            'Sesi Persiapan dibuat otomatis setelah pengambilan bahan dari Gudang.',
        );
        $model = $definition['model'];

        $item = DB::transaction(function () use ($request, $module, $definition, $model, $initializer, $systemUnit): Model {
            if ($module === 'keamanan') {
                return app(SecurityMonitoringService::class)
                    ->startShift($systemUnit->get(), $request->user())
                    ->refresh();
            }

            $item = new $model;
            $values = $this->validatedValues($request, $definition);
            if (str_starts_with($module, 'ba-limbah-')) {
                $values = app(\App\Services\WasteHandoverWorkflow::class)
                    ->normalizeAndValidateSource($values, (int) $systemUnit->id());
            }
            $item->fill($values);
            foreach ($definition['where'] ?? [] as $field => $value) {
                $item->setAttribute($field, $value);
            }
            $this->applySystemValues($item, $request, (int) $systemUnit->id(), creating: true);
            $item->save();
            $this->storeRecordFiles($request, $module, $item, $definition);
            $initializer->initialize($item->refresh(), $request->user());
            if (str_starts_with($module, 'ba-limbah-')) {
                $this->linkWasteHandoverSource($item);
            }

            return $item->refresh();
        });

        return response()->json([
            'message' => $definition['label'].' berhasil dibuat.',
            'data' => $transformer->detail(
                $module,
                $definition,
                $this->loadRelations($item, $definition),
                (int) $systemUnit->id(),
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
        abort_unless($request->user()->can($definition['permission'].'.update'), 403);
        $item = $this->findRecord($definition, $record, (int) $systemUnit->id());
        abort_unless($this->isEditable($item), 422, 'Data sudah dikunci dan tidak dapat diubah.');

        DB::transaction(function () use ($request, $module, $definition, $item, $systemUnit): void {
            $item->fill($this->validatedValues($request, $definition));
            $this->applySystemValues($item, $request, (int) $systemUnit->id());
            $item->save();
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
        abort_unless($request->user()->can($definition['permission'].'.delete'), 403);
        $item = $this->findRecord($definition, $record, (int) $systemUnit->id());
        abort_unless($this->isDeletable($item), 422, 'Data yang sudah mulai dikerjakan tidak dapat dihapus.');
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
        ]);
        $notes = $input['notes'] ?? null;
        $available = collect($this->availableActions($request, $module, $item))->pluck('key');
        abort_unless($available->contains($action), 422, 'Tindakan tidak tersedia pada tahap pekerjaan ini.');

        $item = DB::transaction(function () use ($module, $action, $item, $request, $notes): Model {
            $actor = $request->user();
            $data = $item->getAttributes();

            return match ($module) {
                'persiapan' => $this->runPreparationAction($action, $item, $actor, $notes),
                'pengolahan' => $this->runStandardAction(app(ProcessingWorkflow::class), $action, $item, $actor, $notes),
                'pemorsian' => $this->runStandardAction(app(PortioningWorkflow::class), $action, $item, $actor, $notes),
                'distribusi' => $this->runDistributionAction($action, $item, $actor, $data, $notes),
                'pencucian' => $this->runWashingAction($action, $item, $actor, $data, $notes),
                'kebersihan' => $this->runCleaningAction($action, $item, $actor, $data, $notes),
                'ba-limbah-pencucian', 'ba-limbah-kebersihan' => $this->runWasteHandoverAction(
                    $action, $item, $actor, $notes,
                ),
                default => abort(422, 'Tindakan belum tersedia untuk modul ini.'),
            };
        })->refresh();

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
        abort_unless($module === 'distribusi' && $relation === 'stops', 404);
        [$definition, $parent] = $this->relationContext(
            $request, $module, $record, $relation, $registry, $systemUnit, 'update',
        );
        $stop = $parent->stops()->whereKey($item)->firstOrFail();
        $available = collect($this->relationActions($request, $module, $parent, $relation, $stop))->pluck('key');
        abort_unless($available->contains($action), 422, 'Tindakan tujuan tidak tersedia.');
        $workflow = app(DistributionWorkflow::class);
        $data = $stop->getAttributes();
        match ($action) {
            'arrive' => $workflow->arriveAtStop($parent, $stop, $request->user()),
            'deliver' => $workflow->completeStop($parent, $stop, $request->user(), $data),
            'fail' => $workflow->failStop($parent, $stop, $request->user(), $data),
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
            'gudang-pengambilan' => ['items'],
            'persiapan' => ['items'],
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

        return [
            'can_update' => $editable && $request->user()->can($definition['permission'].'.update'),
            'can_delete' => $this->isDeletable($item)
                && $request->user()->can($definition['permission'].'.delete'),
            'actions' => $module ? $this->availableActions($request, $module, $item) : [],
        ];
    }

    /** @return array<int, array{key:string,label:string,notes_required:bool}> */
    private function availableActions(Request $request, string $module, Model $item): array
    {
        $state = $this->scalarValue($item->getAttribute('state'));
        $status = $this->scalarValue($item->getAttribute('status'));
        $permission = match ($module) {
            'persiapan' => 'preparation',
            'pengolahan' => 'processing',
            'pemorsian' => 'portioning',
            'distribusi' => 'distribution',
            'pencucian' => 'washing',
            'kebersihan' => 'cleaning',
            'ba-limbah-pencucian' => 'washing',
            'ba-limbah-kebersihan' => 'cleaning',
            default => null,
        };
        if ($permission === null) {
            return [];
        }

        $actions = [];
        if ($request->user()->can($permission.'.update')) {
            $actions = match ($module) {
                'persiapan', 'pengolahan', 'pemorsian' => match ($state) {
                    'planned' => [['key' => 'start', 'label' => 'Mulai pekerjaan', 'notes_required' => false]],
                    'in_progress' => [['key' => 'complete', 'label' => 'Selesaikan pekerjaan', 'notes_required' => false]],
                    default => [],
                },
                'distribusi' => match ($state) {
                    'planned' => [['key' => 'claim', 'label' => 'Pilih rute ini', 'notes_required' => false]],
                    'assigned' => [
                        ['key' => 'load', 'label' => 'Mulai memuat', 'notes_required' => false],
                        ['key' => 'release', 'label' => 'Lepas rute', 'notes_required' => false],
                    ],
                    'loaded' => [
                        ['key' => 'depart', 'label' => 'Berangkat', 'notes_required' => false],
                        ['key' => 'release', 'label' => 'Lepas rute', 'notes_required' => false],
                    ],
                    'destinations_completed' => [['key' => 'finish', 'label' => 'Sudah kembali ke SPPG', 'notes_required' => false]],
                    default => [],
                },
                'pencucian' => match ($state) {
                    'planned' => [['key' => 'receive', 'label' => 'Terima ompreng', 'notes_required' => false]],
                    'received' => $item->wasteHandlingCompleted()
                        ? [['key' => 'start', 'label' => 'Mulai pencucian', 'notes_required' => false]]
                        : [['key' => 'waste', 'label' => 'Simpan pencatatan limbah', 'notes_required' => false]],
                    'washing' => [['key' => 'complete', 'label' => 'Selesaikan pencucian', 'notes_required' => false]],
                    'completed' => [['key' => 'ready', 'label' => 'Tandai siap digunakan', 'notes_required' => false]],
                    default => [],
                },
                'kebersihan' => match ($state) {
                    'planned' => [['key' => 'start', 'label' => 'Mulai kebersihan', 'notes_required' => false]],
                    'in_progress' => [['key' => 'complete', 'label' => 'Selesaikan kebersihan', 'notes_required' => false]],
                    default => [],
                },
                default => [],
            };
        }

        if (in_array($state, ['completed', 'ready', 'returned'], true)
            && in_array($status, ['draft', 'revision_required'], true)
            && $request->user()->can($permission.'.submit')) {
            $actions[] = ['key' => 'submit', 'label' => 'Ajukan laporan', 'notes_required' => false];
        }
        if (str_starts_with($module, 'ba-limbah-')
            && in_array($status, ['draft', 'revision_required'], true)
            && $request->user()->can($permission.'.submit')) {
            $actions[] = ['key' => 'submit', 'label' => 'Ajukan berita acara', 'notes_required' => false];
        }
        if (in_array($status, ['submitted', 'division_approved'], true)
            && $request->user()->can($permission.'.approve')) {
            $actions[] = ['key' => 'verify', 'label' => 'Setujui laporan', 'notes_required' => false];
            $actions[] = ['key' => 'revision', 'label' => 'Minta revisi', 'notes_required' => true];
        }

        return $actions;
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
        abort_unless(in_array($operation, $this->relationOperations($module, $relation, $parent), true), 403);
        abort_unless($this->isEditable($parent), 422, 'Data sudah dikunci dan tidak dapat diubah.');
        abort_unless(method_exists($parent, $relation), 404);

        return [$definition, $parent, $definition['relations'][$relation]];
    }

    /** @return array<int, string> */
    private function relationOperations(string $module, string $relation, ?Model $parent = null): array
    {
        return match ($module) {
            'persiapan' => match ($relation) {
                'items' => ['update'],
                'resultDocumentation' => ['create', 'update', 'delete'],
                default => [],
            },
            'pengolahan' => in_array($relation, ['materialUsages', 'temperatureLogs', 'documentations'], true)
                ? ['create', 'update', 'delete'] : [],
            'pemorsian' => match ($relation) {
                'routeAllocations' => $parent?->getAttribute('field_distribution_plan_id')
                    ? ['update'] : ['create', 'update', 'delete'],
                'routeRecords', 'leftoverRecords', 'supplies' => ['create', 'update', 'delete'],
                default => [],
            },
            'distribusi' => $relation === 'stops'
                ? ($parent?->getAttribute('portioning_session_id')
                    ? ['update'] : ['create', 'update', 'delete'])
                : [],
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
            $section['empty_form_fields'] = $this->formFields(
                $relationDefinition,
                $parent->{$key}()->getRelated(),
                $registry,
                $unitId,
                true,
            );
            $section['items'] = collect($section['items'])->map(function (array $item) use (
                $models, $relationDefinition, $registry, $unitId, $editable, $operations,
                $module, $parent, $key, $request,
            ): array {
                $model = $models->get($item['id']);
                if (! $model) {
                    return $item;
                }
                $item['form_fields'] = $this->formFields($relationDefinition, $model, $registry, $unitId, true);
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
        if ($module !== 'distribusi' || $relation !== 'stops'
            || ! $request->user()?->can('distribution.update')
            || $this->scalarValue($parent->getAttribute('state')) !== 'departed') {
            return [];
        }
        $status = $this->scalarValue($item->getAttribute('status'));
        if (! in_array($status, ['planned', 'in_transit', 'arrived'], true)) {
            return [];
        }

        return [
            ['key' => 'arrive', 'label' => 'Tandai tiba'],
            ['key' => 'deliver', 'label' => 'Selesaikan pengiriman'],
            ['key' => 'fail', 'label' => 'Tandai gagal'],
        ];
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
