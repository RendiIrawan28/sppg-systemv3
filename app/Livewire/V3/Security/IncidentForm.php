<?php

namespace App\Livewire\V3\Security;

use App\Enums\FieldIncidentSeverity;
use App\Enums\FieldIncidentStatus;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\FieldIncident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class IncidentForm extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    public ?int $incidentId = null;

    public string $occurredAt = '';

    public string $category = 'suspicious_activity';

    public string $severity = 'medium';

    public string $title = '';

    public string $description = '';

    public string $immediateAction = '';

    public string $resolution = '';

    public ?TemporaryUploadedFile $photo = null;

    public ?string $existingPhoto = null;

    public ?string $actionMessage = null;

    public function mount(?FieldIncident $incident = null): void
    {
        abort_unless($this->allowed($incident?->exists ? 'security.view' : 'security.create'), 403);
        $this->occurredAt = now()->format('Y-m-d\TH:i');

        if (! $incident?->exists) {
            return;
        }

        abort_unless(
            (int) $incident->sppg_unit_id === (int) $this->currentUnit()->getKey()
            && $incident->division_code === 'security',
            404,
        );
        $this->incidentId = $incident->getKey();
        $this->fillFromIncident($incident);
    }

    public function save(): void
    {
        abort_unless($this->allowed($this->incidentId ? 'security.update' : 'security.create'), 403);

        $this->runAction(function (): string {
            $incident = $this->persist();
            $this->incidentId = $incident->getKey();
            $this->fillFromIncident($incident);

            return 'Insiden keamanan berhasil disimpan.';
        });
    }

    public function resolve(): void
    {
        abort_unless($this->allowed('security.close'), 403);

        $this->runAction(function (): string {
            if (blank($this->resolution)) {
                throw ValidationException::withMessages([
                    'resolution' => 'Penyelesaian insiden wajib diisi.',
                ]);
            }

            $incident = $this->persist();
            $incident->update([
                'status' => FieldIncidentStatus::Resolved,
                'resolution' => trim($this->resolution),
                'resolved_at' => now(),
                'resolved_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);
            $this->fillFromIncident($incident->refresh());

            return 'Insiden keamanan ditandai selesai.';
        });
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $incident = $this->incidentId ? $this->incident() : null;

        return view('livewire.v3.security.incident-form', [
            ...$this->shellData($unit),
            'incident' => $incident,
            'severityOptions' => FieldIncidentSeverity::options(),
            'categoryOptions' => [
                'theft' => 'Pencurian',
                'suspicious_activity' => 'Orang/Aktivitas Mencurigakan',
                'disturbance' => 'Keributan',
                'fire' => 'Kebakaran',
                'accident' => 'Kecelakaan',
                'access_violation' => 'Pelanggaran Akses',
                'other' => 'Lainnya',
            ],
            'editable' => ! $incident || $this->allowed('security.update'),
            'canResolve' => $incident && $this->allowed('security.close'),
        ])->layout('layouts.v3', ['title' => $incident ? 'Rincian Insiden Keamanan' : 'Laporkan Insiden']);
    }

    private function persist(): FieldIncident
    {
        $data = $this->validate([
            'occurredAt' => ['required', 'date'],
            'category' => ['required', 'string', 'max:100'],
            'severity' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'immediateAction' => ['nullable', 'string', 'max:5000'],
            'resolution' => ['nullable', 'string', 'max:5000'],
            'photo' => [$this->existingPhoto ? 'nullable' : 'required', 'image', 'max:5120'],
        ]);

        $newPath = $this->photo?->store('v3/security/incidents', 'public');
        $incident = $this->incidentId ? $this->incident() : new FieldIncident;
        $oldPath = $this->existingPhoto;

        try {
            $incident->fill([
                'sppg_unit_id' => $this->currentUnit()->getKey(),
                'incident_date' => Carbon::parse($data['occurredAt'])->toDateString(),
                'occurred_at' => $data['occurredAt'],
                'division_code' => 'security',
                'category' => $data['category'],
                'severity' => $data['severity'],
                'title' => $data['title'],
                'description' => $data['description'],
                'location' => 'Area SPPG',
                'source_type' => 'security',
                'immediate_action' => trim($data['immediateAction']) ?: null,
                'resolution' => trim($data['resolution']) ?: null,
                'evidence_paths' => [$newPath ?: $oldPath],
                'updated_by' => auth()->id(),
            ]);
            if (! $incident->exists) {
                $incident->created_by = auth()->id();
            }
            $incident->save();
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }

        if ($newPath && $oldPath) {
            Storage::disk('public')->delete($oldPath);
        }
        $this->photo = null;

        return $incident->refresh();
    }

    private function fillFromIncident(FieldIncident $incident): void
    {
        $this->occurredAt = $incident->occurred_at?->format('Y-m-d\TH:i') ?? '';
        $this->category = (string) $incident->category;
        $this->severity = $incident->severity->value;
        $this->title = (string) $incident->title;
        $this->description = (string) $incident->description;
        $this->immediateAction = (string) $incident->immediate_action;
        $this->resolution = (string) $incident->resolution;
        $this->existingPhoto = $incident->evidence_paths[0] ?? null;
    }

    private function incident(): FieldIncident
    {
        return FieldIncident::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->where('division_code', 'security')
            ->findOrFail($this->incidentId);
    }

    private function runAction(callable $callback): void
    {
        try {
            $this->actionMessage = $callback();
            $this->resetErrorBag();
        } catch (Throwable $exception) {
            report($exception);
            $message = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->implode(' ')
                : $exception->getMessage();
            $this->addError('action', $message);
        }
    }
}
