<?php

namespace App\Livewire\V3\Operations;

use App\Enums\DistributionRunState;
use App\Enums\DistributionStopStatus;
use App\Enums\OperationalReportStatus;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\DistributionRun;
use App\Models\User;
use App\Services\CleaningWorkflow;
use App\Services\DistributionWorkflow;
use App\Services\OperationalReportApprovalService;
use App\Services\V3\OperationalRecordInitializer;
use App\Services\WashingWorkflow;
use App\Support\V3\OperationalModuleRegistry;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class Form extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    public string $module;

    public ?int $recordId = null;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $relations = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $uploads = [];

    public string $workflowNotes = '';

    public ?string $actionMessage = null;

    public function mount(string $module, OperationalModuleRegistry $registry, ?int $record = null): void
    {
        $definition = $registry->get($module);
        $this->module = $module;
        $this->recordId = $record;
        abort_if(in_array($module, ['distribusi', 'pencucian', 'kebersihan'], true) && ! $record, 404);
        $permission = $record ? '.view' : '.create';
        abort_unless($this->allowed($definition['permission'].$permission), 403);

        if ($record) {
            $this->fillFromRecord($this->record());
        } else {
            foreach ($definition['fields'] as $field) {
                $this->data[$field['name']] = match ($field['type']) {
                    'number' => 0,
                    'boolean' => false,
                    'date' => now()->toDateString(),
                    default => '',
                };
            }
            if (array_key_exists('petugas_id', $this->data)) {
                $this->data['petugas_id'] = auth()->id();
            }
            if (array_key_exists('shift', $this->data)) {
                $this->data['shift'] = 'morning';
            }
            foreach ($definition['relations'] as $name => $relation) {
                $this->relations[$name] = [];
            }
        }
    }

    public function addRelationRow(string $name): void
    {
        $relation = $this->definition()['relations'][$name] ?? null;
        abort_unless(is_array($relation), 404);
        $row = ['_id' => null];
        foreach ($relation['fields'] as $field) {
            $row[$field['name']] = match ($field['type']) {
                'number' => 0, 'boolean' => false,
                'date' => now()->toDateString(), 'datetime' => now()->format('Y-m-d\TH:i'),
                default => '',
            };
        }
        $this->relations[$name][] = $row;
    }

    public function removeRelationRow(string $name, int $index): void
    {
        abort_if((bool) ($this->relations[$name][$index]['_locked'] ?? false), 403);
        unset($this->relations[$name][$index], $this->uploads[$name][$index]);
        $this->relations[$name] = array_values($this->relations[$name]);
        $this->uploads[$name] = array_values($this->uploads[$name] ?? []);
    }

    public function addWashingResultPhoto(): void
    {
        abort_unless(
            $this->module === 'pencucian'
            && $this->allowed('washing.update')
            && $this->record()->state === \App\Enums\WashingSessionState::Washing,
            403,
        );

        $this->relations['documentations'][] = [
            '_id' => null,
            'phase' => 'after',
            'photo_path' => '',
            'caption' => '',
            'captured_at' => now()->format('Y-m-d\TH:i'),
            'sort_order' => count($this->relations['documentations'] ?? []) + 1,
            '_locked' => false,
        ];
    }

    public function save(): void
    {
        if ($this->module === 'distribusi') {
            throw ValidationException::withMessages([
                'distribution' => 'Gunakan aksi pada setiap tujuan untuk menyimpan hasil distribusi.',
            ]);
        }

        $this->runAction(function (): string {
            $record = $this->persist();
            if (! $this->recordId) {
                $this->recordId = $record->getKey();
            }
            $this->fillFromRecord($record->refresh());

            return 'Data '.$this->definition()['label'].' berhasil disimpan.';
        });
    }

    public function delete(): void
    {
        $definition = $this->definition();
        abort_unless($this->allowed($definition['permission'].'.delete'), 403);
        $record = $this->record();
        abort_unless($this->isEditable($record), 403);
        $record->delete();
        session()->flash('v3.status', $definition['label'].' berhasil dihapus.');
        $this->redirectRoute('v3.operations.index', ['module' => $this->module], navigate: true);
    }

    public function checkReadiness(): void
    {
        $this->runAction(function (): string {
            $record = $this->persist();
            $service = $this->workflowService();
            $issues = method_exists($service, 'submissionIssues') ? $service->submissionIssues($record) : [];

            return $issues === [] ? 'Dokumen siap diajukan.' : implode(' ', $issues);
        });
    }

    public function claimRoute(): void
    {
        abort_unless($this->module === 'distribusi', 404);

        $this->runAction(function (): string {
            abort_unless($this->allowed('distribution.update'), 403);
            $this->validate([
                'data.kernet_name' => ['required', 'string', 'max:255'],
                'data.vehicle_name' => ['required', 'string', 'max:255'],
                'data.vehicle_plate' => ['required', 'string', 'max:50'],
            ], [
                'data.kernet_name.required' => 'Nama kernet wajib diisi.',
                'data.vehicle_name.required' => 'Kendaraan wajib diisi.',
                'data.vehicle_plate.required' => 'Nomor polisi wajib diisi.',
            ]);

            $run = app(DistributionWorkflow::class)->claimRoute(
                $this->record(),
                auth()->user(),
                $this->data,
            );
            $this->fillFromRecord($run);

            return "Rute {$run->route_name} berhasil dipilih. Lanjutkan dengan mulai memuat.";
        });
    }

    public function workflow(string $action): void
    {
        $definition = $this->definition();
        $permission = match (true) {
            in_array($action, ['verify', 'revision'], true) => '.approve',
            $action === 'submit' => '.submit',
            default => '.update',
        };
        abort_unless($this->allowed($definition['permission'].$permission), 403);

        $this->runAction(function () use ($action): string {
            $record = $this->record();
            $shouldPersist = $this->module !== 'distribusi'
                && $this->isEditable($record)
                && ! in_array($action, ['claim', 'verify', 'revision'], true);

            if ($shouldPersist) {
                $record = $this->persist();
            }

            $service = $this->workflowService();
            $actor = auth()->user();
            $notes = trim($this->workflowNotes) ?: null;
            if ($action === 'revision' && ! $notes) {
                throw ValidationException::withMessages(['workflowNotes' => 'Catatan revisi wajib diisi.']);
            }
            match ($this->module) {
                'distribusi' => match ($action) {
                    'claim' => $service->claimRoute($record, $actor, $this->data),
                    'release' => $service->releaseRoute($record, $actor, $notes),
                    'load' => $service->startLoading($record, $actor),
                    'depart' => $service->depart($record, $actor, $this->data),
                    'finish' => $service->finish($record, $actor, ['notes' => $notes]),
                    'submit' => $service->submit($record, $actor, $notes),
                    'verify' => $service->verify($record, $actor, $notes),
                    'revision' => $service->requestRevision($record, $actor, $notes),
                },
                'pencucian' => match ($action) {
                    'receive' => $service->receive($record, $actor, [...$this->data, 'notes' => $notes]),
                    'waste' => $service->recordWaste($record, $actor, [...$this->data, 'notes' => $notes]),
                    'start' => $service->start($record, $actor, ['notes' => $notes]),
                    'complete' => $service->complete($record, $actor, [...$this->data, 'notes' => $notes]),
                    'ready' => $service->markReady($record, $actor, $notes), 'submit' => $service->submit($record, $actor, $notes),
                    'verify' => $service->verify($record, $actor, $notes), 'revision' => $service->requestRevision($record, $actor, $notes),
                },
                'kebersihan' => match ($action) {
                    'start' => $service->start($record, $actor, ['notes' => $notes]),
                    'complete' => $service->complete($record, $actor, [
                        'after_condition' => $this->data['after_condition'] ?? null,
                        'notes' => $notes ?: ($this->data['notes'] ?? null),
                    ]),
                    'ready' => $service->markReady($record, $actor, $notes), 'submit' => $service->submit($record, $actor, $notes),
                    'verify' => $service->verify($record, $actor, $notes), 'revision' => $service->requestRevision($record, $actor, $notes),
                },
            };

            $this->workflowNotes = '';
            $this->fillFromRecord($this->record()->refresh());

            return 'Tahap operasional berhasil diperbarui.';
        });
    }

    public function distributionStopWorkflow(int $index, string $action): void
    {
        abort_unless($this->module === 'distribusi' && $this->allowed('distribution.update'), 403);
        abort_unless(in_array($action, ['deliver', 'fail'], true), 404);

        $this->runAction(function () use ($index, $action): string {
            $run = $this->record();
            abort_unless($this->canOperateRoute($run), 403);

            $stopId = (int) ($this->relations['stops'][$index]['_id'] ?? 0);
            $stop = $run->stops()->whereKey($stopId)->firstOrFail();

            $rules = [
                "relations.stops.{$index}.delivered_small_portions" => [
                    'required', 'integer', 'min:0', 'max:'.(int) $stop->small_portions,
                ],
                "relations.stops.{$index}.delivered_large_portions" => [
                    'required', 'integer', 'min:0', 'max:'.(int) $stop->large_portions,
                ],
                "relations.stops.{$index}.containers_sent" => $action === 'deliver'
                    ? ['required', 'integer', 'min:1']
                    : ['nullable', 'integer', 'min:0'],
                "relations.stops.{$index}.recipient_name" => $action === 'deliver'
                    ? ['required', 'string', 'max:255']
                    : ['nullable', 'string', 'max:255'],
                "relations.stops.{$index}.recipient_position" => ['nullable', 'string', 'max:255'],
                "relations.stops.{$index}.failure_reason" => $action === 'fail'
                    ? ['required', 'string', 'max:5000']
                    : ['nullable', 'string', 'max:5000'],
                "uploads.stops.{$index}.handover_photo_path" => ['nullable', 'image', 'max:5120'],
            ];

            $this->validate($rules);

            $row = $this->relations['stops'][$index] ?? [];
            $existingPhoto = $row['handover_photo_path'] ?? null;
            $newPhoto = $this->uploads['stops'][$index]['handover_photo_path'] ?? null;

            if ($action === 'deliver') {
                $deliveredSmall = (int) ($row['delivered_small_portions'] ?? 0);
                $deliveredLarge = (int) ($row['delivered_large_portions'] ?? 0);
                $isPartial = $deliveredSmall < (int) $stop->small_portions
                    || $deliveredLarge < (int) $stop->large_portions;

                if (($deliveredSmall + $deliveredLarge) <= 0) {
                    throw ValidationException::withMessages([
                        'delivery' => 'Gunakan tombol Gagal dikirim jika tidak ada porsi yang diserahkan.',
                    ]);
                }

                if ($isPartial && blank($row['failure_reason'] ?? null)) {
                    throw ValidationException::withMessages([
                        "relations.stops.{$index}.failure_reason" => 'Alasan pengiriman sebagian wajib diisi.',
                    ]);
                }

                if (blank($existingPhoto) && ! ($newPhoto instanceof TemporaryUploadedFile)) {
                    throw ValidationException::withMessages([
                        "uploads.stops.{$index}.handover_photo_path" => 'Foto dokumentasi serah-terima wajib diunggah.',
                    ]);
                }
            }

            $payload = $this->distributionStopPayload($index);
            $workflow = app(DistributionWorkflow::class);

            if ($action === 'deliver') {
                $workflow->completeStop($run, $stop, auth()->user(), $payload);
            } else {
                $workflow->failStop($run, $stop, auth()->user(), $payload);
            }

            $this->fillFromRecord($run->refresh());

            return $action === 'deliver'
                ? 'Hasil serah-terima berhasil disimpan.'
                : 'Tujuan berhasil ditandai gagal dikirim.';
        });
    }

    public function saveStopRevision(int $index): void
    {
        abort_unless($this->module === 'distribusi' && $this->allowed('distribution.update'), 403);

        $this->runAction(function () use ($index): string {
            $run = $this->record();
            abort_unless($this->canOperateRoute($run), 403);
            abort_unless(
                $run->state === DistributionRunState::Returned
                && $run->status === OperationalReportStatus::RevisionRequired,
                403,
            );

            $stopId = (int) ($this->relations['stops'][$index]['_id'] ?? 0);
            $stop = $run->stops()->whereKey($stopId)->firstOrFail();
            $terminalStatuses = implode(',', [
                DistributionStopStatus::Delivered->value,
                DistributionStopStatus::Partial->value,
                DistributionStopStatus::Failed->value,
            ]);

            $this->validate([
                "relations.stops.{$index}.status" => ['required', 'in:'.$terminalStatuses],
                "relations.stops.{$index}.delivered_small_portions" => [
                    'required', 'integer', 'min:0', 'max:'.(int) $stop->small_portions,
                ],
                "relations.stops.{$index}.delivered_large_portions" => [
                    'required', 'integer', 'min:0', 'max:'.(int) $stop->large_portions,
                ],
                "relations.stops.{$index}.containers_sent" => ['required', 'integer', 'min:0'],
                "relations.stops.{$index}.recipient_name" => ['nullable', 'string', 'max:255'],
                "relations.stops.{$index}.recipient_position" => ['nullable', 'string', 'max:255'],
                "relations.stops.{$index}.failure_reason" => ['nullable', 'string', 'max:5000'],
                "uploads.stops.{$index}.handover_photo_path" => ['nullable', 'image', 'max:5120'],
            ]);

            $row = $this->relations['stops'][$index] ?? [];
            $requestedStatus = (string) ($row['status'] ?? '');
            $existingPhoto = $row['handover_photo_path'] ?? null;
            $newPhoto = $this->uploads['stops'][$index]['handover_photo_path'] ?? null;

            if (in_array($requestedStatus, [
                DistributionStopStatus::Delivered->value,
                DistributionStopStatus::Partial->value,
            ], true) && blank($existingPhoto) && ! ($newPhoto instanceof TemporaryUploadedFile)) {
                throw ValidationException::withMessages([
                    "uploads.stops.{$index}.handover_photo_path" => 'Foto dokumentasi wajib tersedia.',
                ]);
            }

            $payload = $this->distributionStopPayload($index);
            $payload['status'] = $requestedStatus;

            app(DistributionWorkflow::class)
                ->reviseStop($run, $stop, auth()->user(), $payload);

            $this->fillFromRecord($run->refresh());

            return 'Koreksi tujuan berhasil disimpan.';
        });
    }

    public function saveRouteRevision(): void
    {
        abort_unless($this->module === 'distribusi', 404);

        throw ValidationException::withMessages([
            'distribution' => 'Pencatatan ompreng dipindahkan ke menu Pengambilan Ompreng.',
        ]);
    }

    public function render(OperationalModuleRegistry $registry)
    {
        $unit = $this->currentUnit();
        $definition = $registry->get($this->module);
        abort_unless($this->allowed($definition['permission'].'.view'), 403);
        $record = $this->recordId ? $this->record() : null;
        if ($record && $this->module === 'kebersihan') {
            $record->loadMissing(['cleaningArea', 'wasteHandoverReport']);
        }
        if ($record && $this->module === 'pencucian') {
            $record->loadMissing('wasteHandoverReport');
        }
        $washingDailyIssues = $record && $this->module === 'pencucian'
            ? app(WashingWorkflow::class)->submissionIssues($record)
            : [];

        $options = [];
        foreach ([...$definition['fields'], ...collect($definition['relations'])->flatMap(fn ($relation) => $relation['fields'])->all()] as $field) {
            if ($field['type'] === 'select') {
                $key = is_array($field['options']) ? md5(serialize($field['options'])) : (string) $field['options'];
                $options[$key] ??= $registry->options($field['options'], $unit->getKey());
            }
        }

        return view('livewire.v3.operations.form', [
            ...$this->shellData($unit), 'definition' => $definition, 'record' => $record,
            'fieldOptions' => $options, 'editable' => ! $record || $this->isEditable($record),
            'canUpdate' => $this->allowed($definition['permission'].'.update'),
            'canSubmit' => $this->allowed($definition['permission'].'.submit'),
            'canApprove' => $this->allowed($definition['permission'].'.approve'),
            'canOperateRoute' => $record ? $this->canOperateRoute($record) : false,
            'actions' => $record ? $this->availableActions($record) : [],
            'washingDailyIssues' => $washingDailyIssues,
        ])->layout('layouts.v3', ['title' => ($record ? 'Rincian ' : 'Tambah ').$definition['label']]);
    }

    private function persist(): Model
    {
        $definition = $this->definition();
        abort_unless($this->allowed($definition['permission'].($this->recordId ? '.update' : '.create')), 403);
        $record = $this->recordId ? $this->record() : new $definition['model'];
        abort_unless(! $this->recordId || $this->isEditable($record), 403);

        if ($this->module === 'distribusi' && $record->exists) {
            $state = $record->state instanceof BackedEnum ? $record->state->value : $record->state;
            $isSupervisor = auth()->user()?->can('distribution.approve') ?? false;
            $isOwner = (int) $record->petugas_id === (int) auth()->id();

            if ($state === DistributionRunState::Planned->value) {
                abort_unless($this->allowed('distribution.update'), 403);
            } else {
                abort_unless($isOwner || $isSupervisor, 403);
            }
        }

        $this->validate($this->rulesForDefinition($definition));

        return DB::transaction(function () use ($definition, $record): Model {
            $values = $this->cleanValues($this->data, $definition['fields']);
            $actor = auth()->user();

            if ($this->module === 'distribusi') {
                $values = array_intersect_key($values, array_flip([
                    'vehicle_name',
                    'vehicle_plate',
                    'kernet_name',
                    'departure_temperature_celsius',
                ]));
            }

            $values['sppg_unit_id'] = $this->currentUnit()->getKey();
            $values['updated_by'] = $actor->getKey();
            if (! $record->exists) {
                $values += ['created_by' => $actor->getKey(), 'source_system' => 'web_v3'];
            }
            if (array_key_exists('petugas_id', $values)) {
                $values['petugas_name_snapshot'] = User::query()->whereKey($values['petugas_id'])->value('name') ?: $actor->name;
            }
            if (array_key_exists('supervisor_id', $values)) {
                $values['supervisor_name_snapshot'] = User::query()->whereKey($values['supervisor_id'])->value('name');
            }
            $record->fill($values)->save();

            foreach ($definition['relations'] as $name => $relationDefinition) {
                $relation = $record->{$name}();
                $kept = [];
                foreach ($this->relations[$name] ?? [] as $index => $row) {
                    $values = $this->cleanValues($row, $relationDefinition['fields']);

                    if ($this->module === 'distribusi' && $name === 'stops') {
                        $values = array_intersect_key($values, array_flip([
                            'delivered_small_portions',
                            'delivered_large_portions',
                            'containers_sent',
                            'recipient_name',
                            'recipient_position',
                            'handover_photo_path',
                            'failure_reason',
                        ]));
                    }

                    if ($this->module === 'kebersihan' && $name === 'checklistItems') {
                        $values = array_intersect_key($values, array_flip(['result', 'notes']));
                        if (in_array($values['result'] ?? null, ['pass', 'fail'], true)) {
                            $values['checked_at'] = now();
                            $values['checked_by'] = $actor->getKey();
                        } else {
                            $values['checked_at'] = null;
                            $values['checked_by'] = null;
                        }
                    }

                    foreach ($relationDefinition['fields'] as $field) {
                        if ($field['type'] !== 'file') {
                            continue;
                        }
                        $upload = $this->uploads[$name][$index][$field['name']] ?? null;
                        if ($upload instanceof TemporaryUploadedFile) {
                            $values[$field['name']] = $upload->store("v3/operations/{$this->module}/{$name}", 'public');
                        }
                    }
                    $id = isset($row['_id']) && $row['_id'] ? (int) $row['_id'] : null;

                    if ($this->module === 'distribusi' && $name === 'stops') {
                        abort_unless($id !== null, 403);
                    }

                    $child = $id ? (clone $relation)->whereKey($id)->firstOrFail() : $relation->make();
                    if (! (bool) ($row['_locked'] ?? false)) {
                        $child->fill($values)->save();
                    }
                    $kept[] = $child->getKey();
                }

                if (! ($this->module === 'distribusi' && $name === 'stops')
                    && ! ($this->module === 'kebersihan' && $name === 'checklistItems')) {
                    (clone $relation)->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))->delete();
                }
            }

            if (method_exists($record, 'recalculateTotals')) {
                $record->recalculateTotals();
            }
            if (! $this->recordId) {
                app(OperationalRecordInitializer::class)->initialize($record->refresh(), $actor);
            }

            return $record->refresh();
        });
    }

    private function fillFromRecord(Model $record): void
    {
        $definition = $this->definition();
        $record->load(array_keys($definition['relations']));
        $this->data = [];
        foreach ($definition['fields'] as $field) {
            $this->data[$field['name']] = $this->inputValue($record->{$field['name']}, $field['type']);
        }

        if ($this->module === 'distribusi'
            && $record->state === DistributionRunState::Planned
            && array_key_exists('driver_name', $this->data)) {
            $this->data['driver_name'] = auth()->user()?->name ?? '';
        }

        $this->relations = [];
        foreach ($definition['relations'] as $name => $relation) {
            $this->relations[$name] = $record->{$name}->map(function ($child) use ($relation): array {
                $row = ['_id' => $child->getKey()];
                foreach ($relation['fields'] as $field) {
                    $row[$field['name']] = $this->inputValue($child->{$field['name']}, $field['type']);
                }
                $row['_locked'] = false;

                return $row;
            })->values()->all();
        }
        $this->uploads = [];
    }

    /** @return array<string, string> */
    private function rulesForDefinition(array $definition): array
    {
        $rules = [];
        foreach ($definition['fields'] as $field) {
            $rules['data.'.$field['name']] = $this->rule($field);
        }
        foreach ($definition['relations'] as $name => $relation) {
            $rules["relations.{$name}"] = ['array'];
            foreach ($relation['fields'] as $field) {
                if ($field['type'] !== 'file') {
                    $rules["relations.{$name}.*.{$field['name']}"] = $this->rule($field);
                }
                if ($field['type'] === 'file') {
                    $rules["uploads.{$name}.*.{$field['name']}"] = ['nullable', 'image', 'max:5120'];
                }
            }
        }

        return $rules;
    }

    private function rule(array $field): array
    {
        $rules = [$field['required'] ? 'required' : 'nullable'];
        if ($field['type'] === 'number') {
            $rules[] = 'numeric';
        }
        if ($field['type'] === 'date') {
            $rules[] = 'date';
        }
        if ($field['type'] === 'datetime') {
            $rules[] = 'date';
        }
        if ($field['type'] === 'boolean') {
            $rules[] = 'boolean';
        }
        if (in_array($field['type'], ['text', 'textarea'], true)) {
            $rules[] = 'string';
            $rules[] = 'max:5000';
        }

        return $rules;
    }

    private function cleanValues(array $values, array $fields): array
    {
        $clean = [];
        foreach ($fields as $field) {
            if ($field['type'] === 'file') {
                if (! empty($values[$field['name']])) {
                    $clean[$field['name']] = $values[$field['name']];
                }

                continue;
            }
            $value = $values[$field['name']] ?? null;
            $clean[$field['name']] = $value === '' ? null : $value;
        }

        return $clean;
    }

    private function inputValue(mixed $value, string $type): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $type === 'date' ? $value->format('Y-m-d') : $value->format('Y-m-d\TH:i');
        }

        return $value ?? match ($type) {
            'number' => 0, 'boolean' => false, default => ''
        };
    }

    /** @return array<string, mixed> */
    private function distributionStopPayload(int $index): array
    {
        $row = $this->relations['stops'][$index] ?? [];
        $photoPath = $row['handover_photo_path'] ?? null;
        $upload = $this->uploads['stops'][$index]['handover_photo_path'] ?? null;

        if ($upload instanceof TemporaryUploadedFile) {
            $photoPath = $upload->store('v3/operations/distribusi/stops', 'public');
        }

        return [
            'delivered_small_portions' => (int) ($row['delivered_small_portions'] ?? 0),
            'delivered_large_portions' => (int) ($row['delivered_large_portions'] ?? 0),
            'containers_sent' => (int) ($row['containers_sent'] ?? 0),
            'recipient_name' => trim((string) ($row['recipient_name'] ?? '')) ?: null,
            'recipient_position' => trim((string) ($row['recipient_position'] ?? '')) ?: null,
            'handover_photo_path' => $photoPath,
            'failure_reason' => trim((string) ($row['failure_reason'] ?? '')) ?: null,
        ];
    }

    private function record(): Model
    {
        $definition = $this->definition();

        return $definition['model']::query()->where('sppg_unit_id', $this->currentUnit()->getKey())->findOrFail($this->recordId);
    }

    private function definition(): array
    {
        return app(OperationalModuleRegistry::class)->get($this->module);
    }

    private function isEditable(Model $record): bool
    {
        return ! method_exists($record, 'isReportEditable') || $record->isReportEditable();
    }

    private function canOperateRoute(Model $record): bool
    {
        if ($this->module !== 'distribusi') {
            return true;
        }

        if (auth()->user()?->can('distribution.approve')) {
            return true;
        }

        $state = $record->state instanceof BackedEnum ? $record->state->value : $record->state;

        if ($state === DistributionRunState::Planned->value) {
            return true;
        }

        return (int) $record->petugas_id === (int) auth()->id();
    }

    private function workflowService(): object
    {
        return app(match ($this->module) {
            'distribusi' => DistributionWorkflow::class, 'pencucian' => WashingWorkflow::class,
            'kebersihan' => CleaningWorkflow::class,
        });
    }

    /** @return array<string, string> */
    private function availableActions(Model $record): array
    {
        $state = $record->state instanceof BackedEnum ? $record->state->value : $record->state;
        $status = $record->status instanceof BackedEnum ? $record->status->value : $record->status;
        $actions = match ($this->module) {
            'distribusi' => match ($state) {
                DistributionRunState::Planned->value => [
                    'claim' => 'Pilih rute ini',
                ],
                DistributionRunState::Assigned->value => [
                    'release' => 'Lepas rute',
                    'load' => 'Mulai memuat',
                ],
                DistributionRunState::Loaded->value => [
                    'release' => 'Lepas rute',
                    'depart' => 'Berangkat',
                ],
                DistributionRunState::DestinationsCompleted->value => [
                    'finish' => 'Sudah kembali ke SPPG',
                ],
                default => [],
            },
            'pencucian' => match ($state) {
                'planned' => ['receive' => 'Terima ompreng'],
                'received' => $record->wasteHandlingCompleted()
                    ? ['waste' => 'Perbarui data limbah', 'start' => 'Mulai pencucian']
                    : ['waste' => 'Simpan pencatatan limbah'],
                'washing' => ['complete' => 'Selesaikan pencucian'],
                'completed' => ['ready' => 'Tandai siap digunakan'],
                default => [],
            },
            'kebersihan' => match ($state) {
                'planned' => ['start' => 'Mulai kebersihan'],
                'in_progress' => ['complete' => 'Selesaikan kebersihan'],
                default => [],
            },
        };

        if ($this->module === 'distribusi'
            && $state === DistributionRunState::Returned->value
            && in_array($status, ['draft', 'revision_required'], true)
            && $record instanceof DistributionRun
            && $record->allRoutesReturned()) {
            $actions['submit'] = 'Ajukan laporan seluruh rute';
        }

        if ($this->module !== 'distribusi'
            && $state === 'ready'
            && in_array($status, ['draft', 'revision_required'], true)) {
            $actions['submit'] = $this->module === 'kebersihan' ? 'Ajukan laporan harian' : 'Ajukan laporan';
        }

        if ($this->module === 'distribusi' && ! $this->canOperateRoute($record)) {
            $actions = [];
        }

        $approvalService = app(OperationalReportApprovalService::class);
        $actor = auth()->user();
        $canReviewStage = $actor !== null && (
            ($status === 'submitted' && ! $approvalService->isHeadSppg($actor))
            || ($status === 'division_approved' && $approvalService->isHeadSppg($actor))
        );
        if ($canReviewStage) {
            $suffix = match ($this->module) {
                'distribusi' => ' seluruh rute',
                'kebersihan' => ' harian',
                default => '',
            };
            $actions['verify'] = 'Setujui laporan'.$suffix;
            $actions['revision'] = 'Minta revisi'.$suffix;
        }

        return $actions;
    }

    private function runAction(callable $callback): void
    {
        try {
            $this->actionMessage = $callback();
            $this->resetErrorBag();
        } catch (Throwable $exception) {
            report($exception);
            $message = $exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : $exception->getMessage();
            $this->addError('action', $message);
        }
    }
}
