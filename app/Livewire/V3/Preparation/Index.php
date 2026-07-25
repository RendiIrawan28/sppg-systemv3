<?php

namespace App\Livewire\V3\Preparation;

use App\Enums\OperationalReportStatus;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\PreparationSession;
use App\Services\PreparationReturnService;
use App\Services\PreparationSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class Index extends Component
{
    use InteractsWithV3Shell, WithFileUploads;

    public ?int $selectedId = null;

    public array $items = [];

    public string $notes = '';

    public mixed $documentationPhoto = null;

    public string $reviewNotes = '';

    public array $returnQuantities = [];

    public array $returnReasons = [];

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('preparation.view'), 403);
    }

    public function select(int $id): void
    {
        $session = $this->record($id)->load('items');
        $this->selectedId = $id;
        $this->notes = (string) $session->notes;
        $this->reviewNotes = (string) $session->review_notes;
        $this->items = $session->items->mapWithKeys(fn ($item) => [$item->id => [
            'processed_quantity' => $item->processed_quantity ?? $item->clean_weight_kg ?? '',
            'waste_quantity' => $item->waste_quantity ?? $item->waste_weight_kg ?? '',
            'notes' => $item->notes ?? '',
        ]])->all();
    }

    public function save(): void
    {
        abort_unless($this->allowed('preparation.update'), 403);
        $session = $this->record($this->selectedId);
        abort_unless($session->state === 'in_progress', 422);
        $this->validate([
            'items.*.processed_quantity' => ['nullable', 'numeric', 'gte:0'],
            'items.*.waste_quantity' => ['nullable', 'numeric', 'gte:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($session): void {
            foreach ($session->items as $item) {
                $row = $this->items[$item->id] ?? [];
                $processed = filled($row['processed_quantity'] ?? null) ? $row['processed_quantity'] : null;
                $waste = filled($row['waste_quantity'] ?? null) ? $row['waste_quantity'] : 0;
                $item->update([
                    'processed_quantity' => $processed,
                    'waste_quantity' => $waste,
                    'condition_status' => 'good',
                    'clean_weight_kg' => $item->unit_snapshot === 'kg' ? $processed : 0,
                    'waste_weight_kg' => $item->unit_snapshot === 'kg' ? $waste : 0,
                    'notes' => filled($row['notes'] ?? null) ? trim($row['notes']) : null,
                ]);
            }
            $session->update(['notes' => filled($this->notes) ? trim($this->notes) : null]);
        });
        session()->flash('v3.status', 'Data Persiapan disimpan.');
    }

    public function start(PreparationSessionService $service): void
    {
        $service->start($this->record($this->selectedId), auth()->user());
        $this->select($this->selectedId);
    }

    public function uploadDocumentation(): void
    {
        abort_unless($this->allowed('preparation.update'), 403);
        $data = $this->validate([
            'documentationPhoto' => ['required', 'image', 'max:5120'],
        ]);
        $session = $this->record($this->selectedId);
        abort_unless(in_array($session->state, ['in_progress', 'completed'], true), 422);
        $path = $data['documentationPhoto']->store('preparation/'.today()->format('Y/m/d'), 'public');
        $existing = $session->resultDocumentation;
        try {
            $session->resultDocumentation()->updateOrCreate([], [
                'photo_path' => $path,
                'captured_at' => now(),
                'created_by' => auth()->id(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }
        if ($existing?->photo_path && $existing->photo_path !== $path) {
            Storage::disk('public')->delete($existing->photo_path);
        }
        $this->reset('documentationPhoto');
        session()->flash('v3.status', 'Foto hasil Persiapan disimpan.');
    }

    public function submitReturn(int $itemId, PreparationReturnService $service): void
    {
        abort_unless($this->allowed('preparation.update'), 403);
        $data = $this->validate([
            "returnQuantities.$itemId" => ['required', 'numeric', 'gt:0'],
            "returnReasons.$itemId" => ['nullable', 'string', 'max:1000'],
        ]);
        $session = $this->record($this->selectedId);
        $item = $session->items()->findOrFail($itemId);
        $service->submit(
            $session,
            $item,
            (float) $data['returnQuantities'][$itemId],
            'good',
            filled($data['returnReasons'][$itemId] ?? null)
                ? $data['returnReasons'][$itemId]
                : 'Bahan tidak digunakan dan dikembalikan oleh Divisi Persiapan.',
            null,
            auth()->user(),
        );
        unset($this->returnQuantities[$itemId], $this->returnReasons[$itemId]);
        session()->flash('v3.status', 'Retur diajukan dan menunggu verifikasi Gudang.');
    }

    public function complete(PreparationSessionService $service): void
    {
        $this->save();
        $service->complete($this->record($this->selectedId), auth()->user());
        $this->select($this->selectedId);
    }

    public function submit(PreparationSessionService $service): void
    {
        $service->submit($this->record($this->selectedId), auth()->user());
        $this->select($this->selectedId);
    }

    public function approve(PreparationSessionService $service): void
    {
        $service->approve($this->record($this->selectedId), auth()->user(), $this->reviewNotes);
        $this->select($this->selectedId);
    }

    public function requestRevision(PreparationSessionService $service): void
    {
        $this->validate(['reviewNotes' => ['required', 'string', 'max:2000']]);
        $service->requestRevision($this->record($this->selectedId), auth()->user(), $this->reviewNotes);
        $this->select($this->selectedId);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $records = PreparationSession::with(['items.returns', 'resultDocumentation', 'withdrawal.taker'])
            ->where('sppg_unit_id', $unit->id)->latest()->get();
        $selected = $this->selectedId ? $records->firstWhere('id', $this->selectedId) : null;

        return view('livewire.v3.preparation.index', [
            ...$this->shellData($unit),
            'records' => $records,
            'selected' => $selected,
            'canEdit' => $this->allowed('preparation.update'),
            'canSubmit' => $this->allowed('preparation.submit'),
            'canApprove' => $this->allowed('preparation.approve'),
            'canExport' => $this->allowed('preparation.export'),
            'statusLabels' => OperationalReportStatus::options(),
        ])->layout('layouts.v3', ['title' => 'Persiapan']);
    }

    private function record(?int $id): PreparationSession
    {
        return PreparationSession::where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
    }
}
