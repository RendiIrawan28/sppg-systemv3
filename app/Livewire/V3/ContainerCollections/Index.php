<?php

namespace App\Livewire\V3\ContainerCollections;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\ContainerCollectionRun;
use App\Models\ContainerCollectionTask;
use App\Services\ContainerCollectionWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    public string $kernetName = '';
    public string $vehicleName = '';
    public string $vehiclePlate = '';
    public string $runNotes = '';

    public array $partialQuantities = [];
    public array $partialNotes = [];
    public array $collectionPhotos = [];

    public ?int $selectedRunId = null;

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('distribution.view'), 403);
    }

    public function startRun(ContainerCollectionWorkflow $workflow): void
    {
        abort_unless($this->allowed('distribution.update'), 403);

        $this->validate([
            'kernetName' => ['nullable', 'string', 'max:255'],
            'vehicleName' => ['nullable', 'string', 'max:255'],
            'vehiclePlate' => ['nullable', 'string', 'max:80'],
            'runNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        $workflow->startRun($this->currentUnit()->getKey(), auth()->user(), [
            'kernet_name' => $this->kernetName,
            'vehicle_name' => $this->vehicleName,
            'vehicle_plate' => $this->vehiclePlate,
            'notes' => $this->runNotes,
        ]);

        $this->reset(['kernetName', 'vehicleName', 'vehiclePlate', 'runNotes']);
        session()->flash('v3.status', 'Kegiatan pengambilan ompreng dimulai.');
    }

    public function collectAll(int $taskId, ContainerCollectionWorkflow $workflow): void
    {
        $run = $this->activeRun();
        $task = $this->task($taskId);
        $path = $this->storePhoto($taskId);

        try {
            $workflow->collectAll($run, $task, auth()->user(), $path);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }

        unset($this->collectionPhotos[$taskId], $this->partialQuantities[$taskId], $this->partialNotes[$taskId]);
        session()->flash('v3.status', "Ompreng {$task->destination_name} sudah dicatat diambil.");
    }

    public function collectPartial(int $taskId, ContainerCollectionWorkflow $workflow): void
    {
        $run = $this->activeRun();
        $task = $this->task($taskId);
        $quantity = (int) ($this->partialQuantities[$taskId] ?? 0);
        $notes = (string) ($this->partialNotes[$taskId] ?? '');
        $path = $this->storePhoto($taskId);

        try {
            $workflow->collectPartial($run, $task, auth()->user(), $quantity, $notes, $path);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }

        unset($this->collectionPhotos[$taskId], $this->partialQuantities[$taskId], $this->partialNotes[$taskId]);
        session()->flash('v3.status', "Pengambilan sebagian {$task->destination_name} berhasil dicatat.");
    }

    public function returnToSppg(ContainerCollectionWorkflow $workflow): void
    {
        $workflow->returnToSppg($this->activeRun(), auth()->user());
        session()->flash('v3.status', 'Ompreng sudah kembali ke SPPG dan sesi Pencucian dibuat.');
    }

    public function showRunDetail(int $runId): void
    {
        abort_unless($this->allowed('distribution.view'), 403);

        $run = $this->visibleRunQuery()
            ->whereKey($runId)
            ->firstOrFail();

        $this->selectedRunId = (int) $run->getKey();
    }

    public function closeRunDetail(): void
    {
        $this->selectedRunId = null;
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $actor = auth()->user();

        $activeRun = ContainerCollectionRun::query()
            ->with(['items.task'])
            ->where('sppg_unit_id', $unit->getKey())
            ->where('driver_id', $actor->getKey())
            ->where('state', ContainerCollectionRun::ACTIVE)
            ->latest('id')
            ->first();

        $tasks = ContainerCollectionTask::query()
            ->with('distributionRun')
            ->where('sppg_unit_id', $unit->getKey())
            ->whereIn('status', [ContainerCollectionTask::PENDING, ContainerCollectionTask::PARTIAL])
            ->where('remaining_containers', '>', 0)
            ->orderBy('delivery_date')
            ->orderBy('available_at')
            ->get();

        $recentRuns = $this->visibleRunQuery()
            ->withCount('items')
            ->latest('id')
            ->limit(15)
            ->get();

        $selectedRun = null;
        if ($this->selectedRunId) {
            $selectedRun = $this->visibleRunQuery()
                ->with([
                    'items.task.items',
                    'items.collector',
                    'washingSession',
                ])
                ->whereKey($this->selectedRunId)
                ->first();

            if (! $selectedRun) {
                $this->selectedRunId = null;
            }
        }

        return view('livewire.v3.container-collections.index', [
            ...$this->shellData($unit),
            'activeRun' => $activeRun,
            'tasks' => $tasks,
            'recentRuns' => $recentRuns,
            'selectedRun' => $selectedRun,
            'canOperate' => $this->allowed('distribution.update'),
        ])->layout('layouts.v3', ['title' => 'Pengambilan Ompreng']);
    }

    private function activeRun(): ContainerCollectionRun
    {
        return ContainerCollectionRun::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->where('driver_id', auth()->id())
            ->where('state', ContainerCollectionRun::ACTIVE)
            ->latest('id')
            ->firstOrFail();
    }

    private function task(int $id): ContainerCollectionTask
    {
        return ContainerCollectionTask::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->findOrFail($id);
    }

    private function visibleRunQuery(): Builder
    {
        $query = ContainerCollectionRun::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey());

        if (! auth()->user()->can('distribution.approve')) {
            $query->where('driver_id', auth()->id());
        }

        return $query;
    }

    private function storePhoto(int $taskId): ?string
    {
        $upload = $this->collectionPhotos[$taskId] ?? null;

        return $upload
            ? $upload->store('distribution/container-collections/'.today()->format('Y/m/d'), 'public')
            : null;
    }
}
