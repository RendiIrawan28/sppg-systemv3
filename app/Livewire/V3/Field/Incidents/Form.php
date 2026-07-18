<?php

namespace App\Livewire\V3\Field\Incidents;

use App\Enums\FieldIncidentSeverity;
use App\Enums\FieldIncidentStatus;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\FieldIncident;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class Form extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    public ?int $incidentId = null;

    public string $incidentDate = '';

    public string $occurredAt = '';

    public string $divisionCode = 'distribution';

    public string $category = 'operational';

    public string $severity = 'medium';

    public string $status = 'open';

    public string $title = '';

    public string $description = '';

    public string $location = '';

    public string $responsibleUserId = '';

    public string $dueAt = '';

    public string $immediateAction = '';

    public string $rootCause = '';

    public string $resolution = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $newEvidence = [];

    /** @var array<int, string> */
    public array $evidencePaths = [];

    public ?string $actionMessage = null;

    public function mount(?FieldIncident $incident = null): void
    {
        abort_unless($this->allowed($incident?->exists ? 'field_incidents.view' : 'field_incidents.create'), 403);
        if ($incident?->exists) {
            abort_unless((int) $incident->sppg_unit_id === (int) $this->currentUnit()->getKey(), 404);
            $this->incidentId = $incident->getKey();
            $this->fillFromIncident($incident);
        } else {
            $this->incidentDate = now()->toDateString();
            $this->occurredAt = now()->format('Y-m-d\TH:i');
        }
    }

    public function save(): void
    {
        $this->runAction(function (): string {
            $incident = $this->persist();
            $this->incidentId = $incident->getKey();
            $this->fillFromIncident($incident);

            return 'Insiden lapangan berhasil disimpan.';
        });
    }

    public function startFollowUp(): void
    {
        abort_unless($this->allowed('field_incidents.update'), 403);
        $this->runAction(function (): string {
            $this->persist()->forceFill(['status' => FieldIncidentStatus::InProgress, 'updated_by' => auth()->id()])->save();
            $this->fillFromIncident($this->incident()->refresh());

            return 'Insiden sedang ditindaklanjuti.';
        });
    }

    public function resolve(): void
    {
        abort_unless($this->allowed('field_incidents.resolve'), 403);
        $this->runAction(function (): string {
            if (blank($this->rootCause) || blank($this->resolution)) {
                throw ValidationException::withMessages(['resolution' => 'Akar masalah dan penyelesaian wajib diisi.']);
            }
            $incident = $this->persist();
            $incident->forceFill([
                'root_cause' => $this->rootCause, 'resolution' => $this->resolution,
                'status' => FieldIncidentStatus::Resolved, 'resolved_at' => now(),
                'resolved_by' => auth()->id(), 'updated_by' => auth()->id(),
            ])->save();
            $this->fillFromIncident($incident->refresh());

            return 'Insiden berhasil diselesaikan.';
        });
    }

    public function delete(): void
    {
        abort_unless($this->allowed('field_incidents.update'), 403);
        $this->incident()->delete();
        session()->flash('v3.status', 'Insiden berhasil dihapus.');
        $this->redirectRoute('v3.field.incidents.index', navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $incident = $this->incidentId ? $this->incident() : null;
        $users = User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id');

        return view('livewire.v3.field.incidents.form', [
            ...$this->shellData($unit), 'incident' => $incident, 'users' => $users,
            'severityOptions' => FieldIncidentSeverity::options(), 'statusOptions' => FieldIncidentStatus::options(),
            'canUpdate' => $this->allowed('field_incidents.update'), 'canResolve' => $this->allowed('field_incidents.resolve'),
        ])->layout('layouts.v3', ['title' => $incident ? 'Rincian Insiden' : 'Catat Insiden']);
    }

    private function persist(): FieldIncident
    {
        abort_unless($this->allowed($this->incidentId ? 'field_incidents.update' : 'field_incidents.create'), 403);
        $data = $this->validate([
            'incidentDate' => ['required', 'date'], 'occurredAt' => ['required', 'date'],
            'divisionCode' => ['required', 'string', 'max:80'], 'category' => ['required', 'string', 'max:100'],
            'severity' => ['required', 'string'], 'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'], 'location' => ['nullable', 'string', 'max:255'],
            'responsibleUserId' => ['nullable', 'integer'], 'dueAt' => ['nullable', 'date'],
            'immediateAction' => ['nullable', 'string', 'max:5000'], 'rootCause' => ['nullable', 'string', 'max:5000'],
            'resolution' => ['nullable', 'string', 'max:5000'], 'newEvidence.*' => ['image', 'max:5120'],
        ]);
        $paths = $this->evidencePaths;
        foreach ($this->newEvidence as $upload) {
            $paths[] = $upload->store('v3/field-incidents', 'public');
        }
        $incident = $this->incidentId ? $this->incident() : new FieldIncident;
        $responsible = filled($data['responsibleUserId']) ? User::query()->find($data['responsibleUserId']) : null;
        $incident->fill([
            'sppg_unit_id' => $this->currentUnit()->getKey(), 'incident_date' => $data['incidentDate'],
            'occurred_at' => $data['occurredAt'], 'division_code' => $data['divisionCode'], 'category' => $data['category'],
            'severity' => $data['severity'], 'title' => $data['title'], 'description' => $data['description'],
            'location' => trim((string) $data['location']) ?: null, 'responsible_user_id' => $responsible?->getKey(),
            'responsible_name_snapshot' => $responsible?->name, 'due_at' => $data['dueAt'] ?: null,
            'immediate_action' => trim((string) $data['immediateAction']) ?: null, 'root_cause' => trim((string) $data['rootCause']) ?: null,
            'resolution' => trim((string) $data['resolution']) ?: null, 'evidence_paths' => $paths,
            'updated_by' => auth()->id(),
        ]);
        if (! $incident->exists) {
            $incident->created_by = auth()->id();
        }
        $incident->save();
        $this->newEvidence = [];

        return $incident->refresh();
    }

    private function fillFromIncident(FieldIncident $incident): void
    {
        $this->incidentDate = $incident->incident_date?->toDateString() ?? '';
        $this->occurredAt = $incident->occurred_at?->format('Y-m-d\TH:i') ?? '';
        $this->divisionCode = (string) $incident->division_code;
        $this->category = (string) $incident->category;
        $this->severity = $incident->severity->value;
        $this->status = $incident->status->value;
        $this->title = (string) $incident->title;
        $this->description = (string) $incident->description;
        $this->location = (string) $incident->location;
        $this->responsibleUserId = (string) $incident->responsible_user_id;
        $this->dueAt = $incident->due_at?->format('Y-m-d\TH:i') ?? '';
        $this->immediateAction = (string) $incident->immediate_action;
        $this->rootCause = (string) $incident->root_cause;
        $this->resolution = (string) $incident->resolution;
        $this->evidencePaths = $incident->evidence_paths ?? [];
    }

    private function incident(): FieldIncident
    {
        return FieldIncident::query()->where('sppg_unit_id', $this->currentUnit()->getKey())->findOrFail($this->incidentId);
    }

    private function runAction(callable $callback): void
    {
        try {
            $this->actionMessage = $callback();
            $this->resetErrorBag();
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('action', $exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : $exception->getMessage());
        }
    }
}
