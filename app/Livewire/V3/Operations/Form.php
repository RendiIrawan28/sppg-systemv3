<?php

namespace App\Livewire\V3\Operations;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\PortioningSupply;
use App\Models\ProcessingMaterialUsage;
use App\Models\User;
use App\Services\CleaningWorkflow;
use App\Services\DistributionWorkflow;
use App\Services\OperationalReportApprovalService;
use App\Services\PortioningWorkflow;
use App\Services\ProcessingWorkflow;
use App\Services\V3\OperationalRecordInitializer;
use App\Services\WashingWorkflow;
use App\Support\V3\OperationalModuleRegistry;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    /** @var array<string, mixed> */
    public array $handover = [];

    public ?TemporaryUploadedFile $handoverPhoto = null;

    public string $workflowNotes = '';

    public ?string $actionMessage = null;

    public function mount(string $module, OperationalModuleRegistry $registry, ?int $record = null): void
    {
        $definition = $registry->get($module);
        $this->module = $module;
        $this->recordId = $record;
        abort_if($module === 'pengolahan', 404);
        abort_if($module === 'distribusi' && ! $record, 404);
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

    public function save(): void
    {
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
            if ($this->isEditable($record) && ! in_array($action, ['verify', 'revision'], true)) {
                $record = $this->persist();
            }
            $service = $this->workflowService();
            $actor = auth()->user();
            $notes = trim($this->workflowNotes) ?: null;
            if ($action === 'revision' && ! $notes) {
                throw ValidationException::withMessages(['workflowNotes' => 'Catatan revisi wajib diisi.']);
            }
            $photo = $this->handoverPhoto?->store("v3/operations/{$this->module}/handover", 'public');
            $handover = array_filter([...$this->handover, 'photo_path' => $photo, 'notes' => $notes], fn ($value) => $value !== '' && $value !== null);

            try {
                match ($this->module) {
                    'pengolahan' => match ($action) {
                        'start' => $service->start($record, $actor), 'complete' => $service->complete($record, $actor),
                        'submit' => $service->submit($record, $actor, $notes),
                        'verify' => $service->verify($record, $actor, $notes), 'revision' => $service->requestRevision($record, $actor, $notes),
                    },
                    'pemorsian' => match ($action) {
                        'start' => $service->start($record, $actor), 'complete' => $service->complete($record, $actor),
                        'handover' => $service->handover($record, $actor, $handover), 'submit' => $service->submit($record, $actor, $notes),
                        'verify' => $service->verify($record, $actor, $notes), 'revision' => $service->requestRevision($record, $actor, $notes),
                    },
                    'distribusi' => match ($action) {
                        'load' => $service->prepareLoad($record, $actor, $this->data), 'depart' => $service->depart($record, $actor, $this->data),
                        'finish' => $service->finish($record, $actor, ['notes' => $notes]), 'submit' => $service->submit($record, $actor, $notes),
                        'verify' => $service->verify($record, $actor, $notes), 'revision' => $service->requestRevision($record, $actor, $notes),
                    },
                    'pencucian' => match ($action) {
                        'receive' => $service->receive($record, $actor, [...$this->data, 'notes' => $notes]),
                        'start' => $service->start($record, $actor, ['notes' => $notes]),
                        'complete' => $service->complete($record, $actor, [...$this->data, 'notes' => $notes]),
                        'ready' => $service->markReady($record, $actor, $notes), 'submit' => $service->submit($record, $actor, $notes),
                        'verify' => $service->verify($record, $actor, $notes), 'revision' => $service->requestRevision($record, $actor, $notes),
                    },
                    'kebersihan' => match ($action) {
                        'start' => $service->start($record, $actor, ['notes' => $notes]),
                        'complete' => $service->complete($record, $actor, ['after_condition' => $this->data['after_condition'] ?? null, 'notes' => $notes]),
                        'ready' => $service->markReady($record, $actor, $notes), 'submit' => $service->submit($record, $actor, $notes),
                        'verify' => $service->verify($record, $actor, $notes), 'revision' => $service->requestRevision($record, $actor, $notes),
                    },
                };
            } catch (Throwable $exception) {
                if ($photo) {
                    Storage::disk('public')->delete($photo);
                }
                throw $exception;
            }

            $this->workflowNotes = '';
            $this->handoverPhoto = null;
            $this->fillFromRecord($this->record()->refresh());

            return 'Tahap operasional berhasil diperbarui.';
        });
    }

    public function distributionStopWorkflow(int $index, string $action): void
    {
        abort_unless($this->module === 'distribusi' && $this->allowed('distribution.update'), 403);
        abort_unless(in_array($action, ['arrive', 'deliver', 'fail'], true), 404);

        $this->runAction(function () use ($index, $action): string {
            $run = $this->persist();
            $stopId = (int) ($this->relations['stops'][$index]['_id'] ?? 0);
            $stop = $run->stops()->whereKey($stopId)->firstOrFail();
            $workflow = app(DistributionWorkflow::class);

            match ($action) {
                'arrive' => $workflow->arriveAtStop($run, $stop, auth()->user()),
                'deliver' => $workflow->completeStop($run, $stop, auth()->user()),
                'fail' => $workflow->failStop($run, $stop, auth()->user()),
            };

            $this->fillFromRecord($run->refresh());

            return match ($action) {
                'arrive' => 'Waktu tiba di tujuan berhasil dicatat.',
                'deliver' => 'Penyerahan makanan berhasil dicatat.',
                'fail' => 'Kegagalan penyerahan dan porsi kembali berhasil dicatat.',
            };
        });
    }

    public function render(OperationalModuleRegistry $registry)
    {
        $unit = $this->currentUnit();
        $definition = $registry->get($this->module);
        abort_unless($this->allowed($definition['permission'].'.view'), 403);
        $record = $this->recordId ? $this->record() : null;

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
            'actions' => $record ? $this->availableActions($record) : [],
        ])->layout('layouts.v3', ['title' => ($record ? 'Rincian ' : 'Tambah ').$definition['label']]);
    }

    private function persist(): Model
    {
        $definition = $this->definition();
        abort_unless($this->allowed($definition['permission'].($this->recordId ? '.update' : '.create')), 403);
        $record = $this->recordId ? $this->record() : new $definition['model'];
        abort_unless(! $this->recordId || $this->isEditable($record), 403);
        $this->validate($this->rulesForDefinition($definition));

        return DB::transaction(function () use ($definition, $record): Model {
            $values = $this->cleanValues($this->data, $definition['fields']);
            $actor = auth()->user();
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
                    $child = $id ? $relation->whereKey($id)->firstOrFail() : $relation->make();
                    if (! (bool) ($row['_locked'] ?? false)) {
                        $child->fill($values)->save();
                    }
                    $kept[] = $child->getKey();
                }
                $isSourcedRelation = ($this->module === 'pengolahan' && $name === 'materialUsages')
                    || ($this->module === 'pemorsian' && $name === 'supplies');
                $deletable = $isSourcedRelation ? $relation->whereNull('source_type') : $relation;
                $deletable->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))->delete();
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
        $this->relations = [];
        foreach ($definition['relations'] as $name => $relation) {
            $this->relations[$name] = $record->{$name}->map(function ($child) use ($relation): array {
                $row = ['_id' => $child->getKey()];
                foreach ($relation['fields'] as $field) {
                    $row[$field['name']] = $this->inputValue($child->{$field['name']}, $field['type']);
                }
                $row['_locked'] = (($this->module === 'pengolahan' && $child instanceof ProcessingMaterialUsage)
                    || ($this->module === 'pemorsian' && $child instanceof PortioningSupply))
                    && filled($child->source_type);

                return $row;
            })->values()->all();
        }
        $this->uploads = [];
        $this->handover = match ($this->module) {
            'pengolahan' => [],
            'pemorsian' => ['small_portions' => $this->data['actual_small_portions'] ?: 0, 'large_portions' => $this->data['actual_large_portions'] ?: 0, 'received_by_name' => ''],
            default => [],
        };
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
        if ($this->module === 'distribusi'
            && ($record->state instanceof BackedEnum ? $record->state->value : $record->state) === 'returned') {
            return false;
        }

        return ! method_exists($record, 'isReportEditable') || $record->isReportEditable();
    }

    private function workflowService(): object
    {
        return app(match ($this->module) {
            'pengolahan' => ProcessingWorkflow::class, 'pemorsian' => PortioningWorkflow::class,
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
            'pengolahan' => match ($state) {
                'planned' => ['start' => 'Mulai pengolahan'], 'in_progress' => ['complete' => 'Selesaikan pengolahan'], default => []
            },
            'pemorsian' => match ($state) {
                'planned' => ['start' => 'Mulai pemorsian'], 'in_progress' => ['complete' => 'Selesaikan pemorsian'], 'completed' => ['handover' => 'Serahkan ke Distribusi'], default => []
            },
            'distribusi' => match ($state) {
                'planned' => ['load' => 'Mulai memuat'], 'loaded' => ['depart' => 'Mulai perjalanan'], 'departed' => ['finish' => 'Kembali ke SPPG'], default => []
            },
            'pencucian' => match ($state) {
                'planned' => ['receive' => 'Terima ompreng'], 'received' => ['start' => 'Mulai pencucian'], 'washing' => ['complete' => 'Selesaikan pencucian'], 'completed' => ['ready' => 'Tandai siap digunakan'], default => []
            },
            'kebersihan' => match ($state) {
                'planned' => ['start' => 'Mulai kebersihan'], 'in_progress' => ['complete' => 'Selesaikan kebersihan'], 'completed' => ['ready' => 'Tandai area siap'], default => []
            },
        };
        if ($this->module !== 'distribusi'
            && (
                ($this->module === 'pengolahan' && $state === 'completed')
                || in_array($state, ['handed_over', 'returned', 'ready'], true)
            )
            && in_array($status, ['draft', 'revision_required'], true)) {
            $actions['submit'] = 'Ajukan laporan';
        }
        $approvalService = app(OperationalReportApprovalService::class);
        $actor = auth()->user();
        $canReviewStage = $actor !== null && (
            ($status === 'submitted' && ! $approvalService->isHeadSppg($actor))
            || ($status === 'division_approved' && $approvalService->isHeadSppg($actor))
        );
        if ($canReviewStage) {
            $actions['verify'] = 'Setujui / verifikasi';
            $actions['revision'] = 'Minta revisi';
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
