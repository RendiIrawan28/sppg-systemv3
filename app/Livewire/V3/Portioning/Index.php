<?php

namespace App\Livewire\V3\Portioning;

use App\Enums\OperationalReportStatus;
use App\Enums\PortioningSessionState;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\PortioningRouteRecord;
use App\Models\PortioningSession;
use App\Services\PortioningWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    public ?int $selectedId = null;

    /** @var array<int, array<string, mixed>> */
    public array $routeRecords = [];

    /** @var array<string, mixed> */
    public array $routeForm = [
        'id' => null,
        'route_name' => '',
        'small_portions' => '',
        'large_portions' => '',
        'notes' => '',
        'photo_path' => null,
        'photo_original_name' => null,
    ];

    public mixed $routePhoto = null;

    /** @var array<int, array<string, mixed>> */
    public array $leftovers = [];

    /** @var array<int, mixed> */
    public array $leftoverPhotos = [];

    public string $leftoverMode = '';

    public string $notes = '';

    public string $reviewNotes = '';

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('portioning.view'), 403);
    }

    public function select(int $id): void
    {
        $session = $this->record($id)->load(['routeRecords', 'leftoverRecords']);

        $this->selectedId = $session->id;
        $this->notes = str_starts_with((string) $session->notes, 'Dibuat dari rencana distribusi ')
            ? ''
            : (string) $session->notes;
        $this->reviewNotes = (string) $session->review_notes;
        $this->leftoverMode = (string) ($session->leftover_mode ?: (
            $session->leftoverRecords->isNotEmpty() ? 'present' : ''
        ));
        $this->leftoverPhotos = [];
        $this->resetRouteForm();

        $this->routeRecords = $session->routeRecords
            ->map(fn (PortioningRouteRecord $route): array => [
                'id' => $route->id,
                'route_name' => $route->route_name,
                'small_portions' => $route->small_portions,
                'large_portions' => $route->large_portions,
                'notes' => $route->notes,
                'photo_path' => $route->photo_path,
                'photo_original_name' => $route->photo_original_name,
                'completed_at' => $route->completed_at?->format('d-m-Y H:i'),
            ])
            ->all();

        $this->leftovers = $session->leftoverRecords
            ->map(fn ($leftover): array => [
                'leftover_id' => $leftover->id,
                'food_type' => (string) $leftover->food_type,
                'quantity' => $leftover->quantity,
                'unit_name' => (string) ($leftover->unit_name ?: 'kg'),
                'notes' => (string) $leftover->notes,
                'photo_path' => $leftover->photo_path,
                'photo_original_name' => $leftover->photo_original_name,
            ])
            ->values()
            ->all();

        if ($this->leftoverMode === 'present' && $this->leftovers === []) {
            $this->addLeftover();
        }
    }

    public function start(PortioningWorkflow $workflow): void
    {
        $session = $workflow->start($this->record($this->selectedId), auth()->user());
        $this->select($session->id);
        session()->flash('v3.status', 'Pemorsian dimulai. Catat rute pertama beserta jumlah dan dokumentasinya.');
    }

    public function saveRoute(): void
    {
        abort_unless($this->allowed('portioning.update'), 403);
        $session = $this->editableSession();

        $this->validate([
            'routeForm.id' => ['nullable', 'integer'],
            'routeForm.route_name' => ['required', 'string', 'max:100'],
            'routeForm.small_portions' => ['required', 'integer', 'min:0'],
            'routeForm.large_portions' => ['required', 'integer', 'min:0'],
            'routeForm.notes' => ['nullable', 'string', 'max:1000'],
            'routePhoto' => ['nullable', 'image', 'max:5120'],
        ]);

        $small = (int) $this->routeForm['small_portions'];
        $large = (int) $this->routeForm['large_portions'];
        if ($small + $large <= 0) {
            throw ValidationException::withMessages([
                'routeForm.small_portions' => 'Jumlah porsi pada rute harus lebih dari nol.',
            ]);
        }

        $routeName = trim((string) $this->routeForm['route_name']);
        $duplicate = $session->routeRecords()
            ->whereRaw('LOWER(route_name) = ?', [mb_strtolower($routeName)])
            ->when(
                filled($this->routeForm['id']),
                fn ($query) => $query->where('id', '!=', (int) $this->routeForm['id']),
            )
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'routeForm.route_name' => 'Nama rute sudah digunakan pada sesi ini.',
            ]);
        }

        $existing = filled($this->routeForm['id'])
            ? $session->routeRecords()->findOrFail($this->routeForm['id'])
            : null;
        if (! $existing?->photo_path && ! ($this->routePhoto instanceof TemporaryUploadedFile)) {
            throw ValidationException::withMessages([
                'routePhoto' => 'Satu foto dokumentasi rute wajib dipilih.',
            ]);
        }

        $newPath = null;
        $oldPath = null;

        try {
            DB::transaction(function () use (
                $session,
                $existing,
                $routeName,
                $small,
                $large,
                &$newPath,
                &$oldPath,
            ): void {
                $path = $existing?->photo_path;
                $originalName = $existing?->photo_original_name;
                if ($this->routePhoto instanceof TemporaryUploadedFile) {
                    $originalName = $this->routePhoto->getClientOriginalName();
                    $path = $this->routePhoto->store('portioning/routes/'.now()->format('Y/m/d'), 'public');
                    $newPath = $path;
                    if ($existing?->photo_path && $existing->photo_path !== $path) {
                        $oldPath = $existing->photo_path;
                    }
                }

                $session->routeRecords()->updateOrCreate(
                    ['id' => $existing?->id],
                    [
                        'route_name' => $routeName,
                        'small_portions' => $small,
                        'large_portions' => $large,
                        'photo_path' => $path,
                        'photo_original_name' => $originalName,
                        'notes' => filled($this->routeForm['notes'])
                            ? trim((string) $this->routeForm['notes'])
                            : null,
                        'completed_at' => now(),
                        'created_by' => $existing?->created_by ?: auth()->id(),
                        'updated_by' => auth()->id(),
                    ],
                );

                $session->update([
                    'petugas_id' => $session->petugas_id ?: auth()->id(),
                    'petugas_name_snapshot' => $session->petugas_name_snapshot ?: auth()->user()->name,
                    'updated_by' => auth()->id(),
                ]);
                $session->recalculateTotals();
            });
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }
            throw $exception;
        }

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }
        $this->select($session->id);
        session()->flash('v3.status', "Data {$routeName} berhasil disimpan. Anda dapat menambahkan rute berikutnya.");
    }

    public function editRoute(int $id): void
    {
        $route = $this->editableSession()->routeRecords()->findOrFail($id);
        $this->routeForm = [
            'id' => $route->id,
            'route_name' => $route->route_name,
            'small_portions' => $route->small_portions,
            'large_portions' => $route->large_portions,
            'notes' => (string) $route->notes,
            'photo_path' => $route->photo_path,
            'photo_original_name' => $route->photo_original_name,
        ];
        $this->routePhoto = null;
    }

    public function deleteRoute(int $id): void
    {
        abort_unless($this->allowed('portioning.update'), 403);
        $session = $this->editableSession();
        $route = $session->routeRecords()->findOrFail($id);
        $path = $route->photo_path;
        $route->delete();
        $session->recalculateTotals();
        if ($path) {
            Storage::disk('public')->delete($path);
        }
        $this->select($session->id);
        session()->flash('v3.status', 'Rute dihapus dari pencatatan Pemorsian.');
    }

    public function resetRouteForm(): void
    {
        $this->routeForm = [
            'id' => null,
            'route_name' => '',
            'small_portions' => '',
            'large_portions' => '',
            'notes' => '',
            'photo_path' => null,
            'photo_original_name' => null,
        ];
        $this->routePhoto = null;
    }

    public function setLeftoverMode(string $mode): void
    {
        abort_unless(in_array($mode, ['none', 'present'], true), 422);
        $this->leftoverMode = $mode;
        if ($mode === 'present' && $this->leftovers === []) {
            $this->addLeftover();
        }
    }

    public function addLeftover(): void
    {
        $this->leftovers[] = [
            'leftover_id' => null,
            'food_type' => '',
            'quantity' => '',
            'unit_name' => 'kg',
            'notes' => '',
            'photo_path' => null,
            'photo_original_name' => null,
        ];
    }

    public function removeLeftover(int $index): void
    {
        unset($this->leftovers[$index], $this->leftoverPhotos[$index]);
        $this->leftovers = array_values($this->leftovers);
        $this->leftoverPhotos = array_values($this->leftoverPhotos);
    }

    public function saveFinalData(): void
    {
        $session = $this->persistFinalData();
        $this->select($session->id);
        session()->flash('v3.status', 'Data sisa makanan dan catatan Pemorsian berhasil disimpan.');
    }

    public function complete(PortioningWorkflow $workflow): void
    {
        $session = $this->persistFinalData();
        $session = $workflow->complete($session, auth()->user());
        $this->select($session->id);
        session()->flash('v3.status', 'Pemorsian selesai. Total hasil seluruh rute telah dicatat.');
    }

    public function submit(PortioningWorkflow $workflow): void
    {
        $session = $workflow->submit($this->record($this->selectedId), auth()->user());
        $this->select($session->id);
        session()->flash('v3.status', 'Laporan Pemorsian diajukan kepada Kepala Divisi Pemorsian.');
    }

    public function approve(PortioningWorkflow $workflow): void
    {
        $session = $workflow->verify(
            $this->record($this->selectedId),
            auth()->user(),
            filled($this->reviewNotes) ? trim($this->reviewNotes) : null,
        );
        $this->select($session->id);
        session()->flash(
            'v3.status',
            $session->status === OperationalReportStatus::Verified
                ? 'Laporan Pemorsian telah diverifikasi Asisten Lapangan dan siap diekspor.'
                : 'Laporan disetujui Kepala Divisi Pemorsian dan menunggu verifikasi Asisten Lapangan.',
        );
    }

    public function requestRevision(PortioningWorkflow $workflow): void
    {
        $this->validate(['reviewNotes' => ['required', 'string', 'max:2000']]);
        $session = $workflow->requestRevision(
            $this->record($this->selectedId),
            auth()->user(),
            $this->reviewNotes,
        );
        $this->select($session->id);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $records = PortioningSession::query()
            ->with([
                'processingBatch',
                'preparationOutputWithdrawals.output',
                'routeAllocations',
                'routeRecords',
                'leftoverRecords',
                'petugas',
            ])
            ->where('sppg_unit_id', $unit->id)
            ->latest('portioning_date')
            ->latest('id')
            ->get();
        $selected = $this->selectedId ? $records->firstWhere('id', $this->selectedId) : null;
        $routesRecorded = $this->routeRecords !== [];
        $leftoverDeclared = $this->leftoverMode === 'none'
            || ($this->leftoverMode === 'present' && $this->leftovers !== []);

        return view('livewire.v3.portioning.index', [
            ...$this->shellData($unit),
            'records' => $records,
            'selected' => $selected,
            'routesRecorded' => $routesRecorded,
            'leftoverDeclared' => $leftoverDeclared,
            'canEdit' => $this->allowed('portioning.update'),
            'canSubmit' => $this->allowed('portioning.submit'),
            'canApprove' => $this->allowed('portioning.approve'),
            'canExport' => $this->allowed('portioning.export'),
        ])->layout('layouts.v3', ['title' => 'Pemorsian']);
    }

    private function persistFinalData(): PortioningSession
    {
        abort_unless($this->allowed('portioning.update'), 403);
        $session = $this->editableSession();
        $session->recalculateTotals();

        if (! $session->routeRecords()->exists()) {
            throw ValidationException::withMessages(['routes' => 'Minimal satu rute wajib disimpan.']);
        }

        $hasVariance = (int) $session->actual_small_portions !== (int) $session->target_small_portions
            || (int) $session->actual_large_portions !== (int) $session->target_large_portions;
        if ($hasVariance && blank($this->notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Catatan wajib diisi karena akumulasi porsi belum sama dengan target harian.',
            ]);
        }

        $rules = [
            'leftoverMode' => ['required', 'in:none,present'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
        if ($this->leftoverMode === 'present') {
            $rules += [
                'leftovers' => ['required', 'array', 'min:1'],
                'leftovers.*.leftover_id' => ['nullable', 'integer'],
                'leftovers.*.food_type' => ['required', 'string', 'max:255'],
                'leftovers.*.quantity' => ['required', 'numeric', 'gt:0'],
                'leftovers.*.unit_name' => ['required', 'string', 'max:50'],
                'leftovers.*.notes' => ['nullable', 'string', 'max:1000'],
                'leftoverPhotos.*' => ['nullable', 'image', 'max:5120'],
            ];
        }
        $this->validate($rules);

        if ($this->leftoverMode === 'present') {
            foreach ($this->leftovers as $index => $leftover) {
                if (blank($leftover['photo_path'])
                    && ! (($this->leftoverPhotos[$index] ?? null) instanceof TemporaryUploadedFile)) {
                    throw ValidationException::withMessages([
                        "leftoverPhotos.{$index}" => 'Foto sisa makanan wajib dipilih.',
                    ]);
                }
            }
        }

        $newPaths = [];
        $oldPaths = [];

        try {
            DB::transaction(function () use ($session, &$newPaths, &$oldPaths): void {
                $session->update([
                    'notes' => filled($this->notes) ? trim($this->notes) : null,
                    'leftover_mode' => $this->leftoverMode,
                    'updated_by' => auth()->id(),
                ]);

                if ($this->leftoverMode === 'none') {
                    $oldPaths = $session->leftoverRecords()->pluck('photo_path')->filter()->all();
                    $session->leftoverRecords()->delete();

                    return;
                }

                $keptIds = [];
                foreach ($this->leftovers as $index => $data) {
                    $existing = filled($data['leftover_id'])
                        ? $session->leftoverRecords()->find($data['leftover_id'])
                        : null;
                    $path = $data['photo_path'];
                    $originalName = $data['photo_original_name'] ?? $existing?->photo_original_name;
                    $upload = $this->leftoverPhotos[$index] ?? null;
                    if ($upload instanceof TemporaryUploadedFile) {
                        $originalName = $upload->getClientOriginalName();
                        $path = $upload->store('portioning/leftovers/'.now()->format('Y/m/d'), 'public');
                        $newPaths[] = $path;
                        if ($existing?->photo_path && $existing->photo_path !== $path) {
                            $oldPaths[] = $existing->photo_path;
                        }
                    }

                    $quantity = (float) $data['quantity'];
                    $unitName = trim((string) $data['unit_name']);
                    $leftover = $session->leftoverRecords()->updateOrCreate(
                        ['id' => $existing?->id],
                        [
                            'checked_at' => now(),
                            'food_type' => trim((string) $data['food_type']),
                            'quantity' => $quantity,
                            'unit_name' => $unitName,
                            'notes' => filled($data['notes']) ? trim((string) $data['notes']) : null,
                            'photo_path' => $path,
                            'photo_original_name' => $originalName,
                            'created_by' => $existing?->created_by ?: auth()->id(),
                        ],
                    );
                    $keptIds[] = $leftover->id;
                }

                $stale = $session->leftoverRecords()->whereNotIn('id', $keptIds)->get();
                $oldPaths = array_merge($oldPaths, $stale->pluck('photo_path')->filter()->all());
                $stale->each->delete();
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete(array_values(array_unique($newPaths)));
            throw $exception;
        }

        Storage::disk('public')->delete(array_values(array_unique(array_diff($oldPaths, $newPaths))));

        return $session->refresh();
    }

    private function editableSession(): PortioningSession
    {
        $session = $this->record($this->selectedId);
        $editableRevision = $session->state === PortioningSessionState::Completed
            && $session->status === OperationalReportStatus::RevisionRequired;
        abort_unless($session->state === PortioningSessionState::InProgress || $editableRevision, 422);

        return $session;
    }

    private function record(?int $id): PortioningSession
    {
        return PortioningSession::query()
            ->where('sppg_unit_id', $this->currentUnit()->id)
            ->findOrFail($id);
    }
}
