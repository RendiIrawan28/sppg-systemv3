<?php

namespace App\Livewire\V3\Preparation;

use App\Enums\OperationalReportStatus;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Livewire\V3\Concerns\FiltersByWorkDate;
use App\Models\PreparationSession;
use App\Models\ProcessingBatch;
use App\Models\PortioningSession;
use App\Services\PreparationReturnService;
use App\Services\PreparationOutputService;
use App\Services\PreparationSessionService;
use App\Services\PreparationWasteReportSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class Index extends Component
{
    use InteractsWithV3Shell, FiltersByWorkDate, WithFileUploads;

    public ?int $selectedId = null;

    public array $items = [];

    public string $notes = '';

    public array $itemPhotos = [];

    public string $reviewNotes = '';

    public array $returnQuantities = [];

    public array $returnReasons = [];
    public array $handoverTargets = [];
    public array $handoverQuantities = [];

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('preparation.view'), 403);
    }

    public function select(int $id): void
    {
        $session = $this->record($id)->load('items.outputs');
        $this->selectedId = $id;
        $this->notes = (string) $session->notes;
        $this->reviewNotes = (string) $session->review_notes;
        $this->items = $session->items->mapWithKeys(fn ($item) => [$item->id => [
            'processed_quantity' => $item->processed_quantity ?? $item->clean_weight_kg ?? '',
            'waste_quantity' => $item->waste_quantity ?? $item->waste_weight_kg ?? '',
            'condition_status' => $item->condition_status ?: 'good',
            'target_division' => $item->output_target_division ?: $item->outputs->first()?->target_division ?: 'processing',
            'notes' => $item->notes ?? '',
        ]])->all();
        $this->itemPhotos = [];
    }

    public function save(
        PreparationOutputService $outputs,
        PreparationWasteReportSyncService $wasteReports,
    ): void
    {
        abort_unless($this->allowed('preparation.update'), 403);
        $session = $this->record($this->selectedId);
        abort_unless($session->state === 'in_progress', 422);
        $this->validate([
            'items.*.processed_quantity' => ['nullable', 'numeric', 'gte:0'],
            'items.*.waste_quantity' => ['nullable', 'numeric', 'gte:0'],
            'items.*.condition_status' => ['required', 'in:good,fair,damaged'],
            'items.*.target_division' => ['required', 'in:processing,portioning'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'itemPhotos.*' => ['nullable', 'image', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $newPaths = [];
        $oldPaths = [];
        DB::transaction(function () use ($session, &$newPaths, &$oldPaths): void {
            foreach ($session->items as $item) {
                $row = $this->items[$item->id] ?? [];
                $processed = filled($row['processed_quantity'] ?? null) ? $row['processed_quantity'] : null;
                $waste = filled($row['waste_quantity'] ?? null) ? $row['waste_quantity'] : 0;
                $item->update([
                    'processed_quantity' => $processed,
                    'waste_quantity' => $waste,
                    'condition_status' => $row['condition_status'] ?? 'good',
                    'output_target_division' => $row['target_division'] ?? 'processing',
                    'clean_weight_kg' => $item->unit_snapshot === 'kg' ? $processed : 0,
                    'waste_weight_kg' => $item->unit_snapshot === 'kg' ? $waste : 0,
                    'notes' => filled($row['notes'] ?? null) ? trim($row['notes']) : null,
                ]);
                $upload = $this->itemPhotos[$item->id] ?? null;
                if ($upload instanceof TemporaryUploadedFile) {
                    $path = $upload->store('preparation/items/'.today()->format('Y/m/d'), 'public');
                    $newPaths[] = $path;
                    $existing = $item->resultDocumentation;
                    if ($existing?->photo_path) $oldPaths[] = $existing->photo_path;
                    $item->resultDocumentation()->updateOrCreate([], [
                        'preparation_session_id' => $session->id,
                        'photo_path' => $path,
                        'captured_at' => now(),
                        'created_by' => auth()->id(),
                    ]);
                }
            }
            $session->update(['notes' => filled($this->notes) ? trim($this->notes) : null]);
        });
        Storage::disk('public')->delete(array_diff($oldPaths, $newPaths));
        $session->refresh();
        $outputs->syncSessionOutputs($session, auth()->user(), collect($this->items)->mapWithKeys(
            fn ($row, $id) => [(int) $id => $row['target_division'] ?? 'processing']
        )->all());
        $wasteReports->sync($session, auth()->user());
        $this->itemPhotos = [];
        session()->flash('v3.status', 'Data Persiapan disimpan.');
    }

    public function start(PreparationSessionService $service): void
    {
        $service->start($this->record($this->selectedId), auth()->user());
        $this->select($this->selectedId);
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
        $this->save(app(PreparationOutputService::class), app(PreparationWasteReportSyncService::class));
        $service->complete($this->record($this->selectedId), auth()->user());
        $this->select($this->selectedId);
    }

    public function handoverOutput(int $itemId, PreparationOutputService $service): void
    {
        $session = $this->record($this->selectedId)->load('items.outputs');
        $item = $session->items->firstWhere('id', $itemId);
        $output = $item?->outputs->first();
        abort_unless($output && $session->state === 'completed', 422);
        $targetId = (int) ($this->handoverTargets[$itemId] ?? 0);
        $quantity = (float) ($this->handoverQuantities[$itemId] ?? $output->available_quantity);
        $service->requestWithdrawal($output, auth()->user(), [
            'destination_division' => $output->target_division,
            'requested_quantity' => $quantity,
            'processing_batch_id' => $output->target_division === 'processing' ? $targetId : null,
            'portioning_session_id' => $output->target_division === 'portioning' ? $targetId : null,
            'notes' => 'Diserahkan oleh Divisi Persiapan.',
        ]);
        unset($this->handoverTargets[$itemId], $this->handoverQuantities[$itemId]);
        session()->flash('v3.status', 'Hasil siap diserahkan dan menunggu diterima divisi tujuan.');
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
        $records = PreparationSession::with(['items.returns', 'items.resultDocumentation', 'items.outputs', 'withdrawal.taker', 'wasteHandoverReport'])
            ->where('sppg_unit_id', $unit->id)
            ->whereDate('preparation_date', $this->selectedWorkDate())
            ->latest()
            ->get();
        $attentionRecords = PreparationSession::query()
            ->where('sppg_unit_id', $unit->id)
            ->whereDate('preparation_date', '!=', $this->selectedWorkDate())
            ->where('state', '!=', 'completed')
            ->latest('preparation_date')
            ->limit(10)
            ->get();
        $selected = $this->selectedId
            ? $this->record($this->selectedId)->load(['items.returns', 'items.resultDocumentation', 'items.outputs', 'withdrawal.taker', 'wasteHandoverReport'])
            : null;

        return view('livewire.v3.preparation.index', [
            ...$this->shellData($unit),
            'records' => $records,
            'attentionRecords' => $attentionRecords,
            'selected' => $selected,
            'canEdit' => $this->allowed('preparation.update'),
            'canSubmit' => $this->allowed('preparation.submit'),
            'canApprove' => $this->allowed('preparation.approve'),
            'canExport' => $this->allowed('preparation.export'),
            'statusLabels' => OperationalReportStatus::options(),
            'processingTargets' => ProcessingBatch::query()->where('sppg_unit_id', $unit->id)->where('state', 'in_progress')->whereDate('production_date', $this->selectedWorkDate())->pluck('batch_number', 'id'),
            'portioningTargets' => PortioningSession::query()->where('sppg_unit_id', $unit->id)->where('state', 'in_progress')->whereDate('portioning_date', $this->selectedWorkDate())->pluck('session_number', 'id'),
        ])->layout('layouts.v3', ['title' => 'Persiapan']);
    }

    protected function afterWorkDateChanged(): void
    {
        $this->selectedId = null;
    }

    private function record(?int $id): PreparationSession
    {
        return PreparationSession::where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
    }
}
