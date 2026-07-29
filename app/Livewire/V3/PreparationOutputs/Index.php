<?php

namespace App\Livewire\V3\PreparationOutputs;

use App\Enums\PortioningSessionState;
use App\Enums\ProcessingBatchState;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\PortioningSession;
use App\Models\PreparationOutput;
use App\Models\PreparationOutputWithdrawal;
use App\Models\PreparationSession;
use App\Models\PreparationSessionItem;
use App\Models\ProcessingBatch;
use App\Services\PreparationOutputService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    public ?int $sessionId = null;
    public ?int $itemId = null;
    public string $outputName = '';
    public string $quantity = '';
    public string $unitSnapshot = '';
    public string $targetDivision = 'processing';
    public string $storageLocation = '';
    public string $expiresAt = '';
    public string $outputNotes = '';
    public mixed $outputPhoto = null;

    public array $requestQuantities = [];
    public array $requestDivisions = [];
    public array $processingBatchIds = [];
    public array $portioningSessionIds = [];
    public array $requestNotes = [];
    public array $verifiedQuantities = [];
    public array $reviewNotes = [];

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless(
            $this->allowed('preparation.view')
            || $this->allowed('processing.view')
            || $this->allowed('portioning.view'),
            403,
        );
    }

    public function updatedSessionId(): void
    {
        $this->itemId = null;
        $this->unitSnapshot = '';
    }

    public function updatedItemId(): void
    {
        if (! $this->itemId) {
            return;
        }

        $item = PreparationSessionItem::query()->find($this->itemId);
        $this->unitSnapshot = (string) ($item?->unit_snapshot ?: 'kg');
        $this->outputName = (string) ($item?->ingredient_name_snapshot ?: '');
    }

    public function createOutput(PreparationOutputService $service): void
    {
        abort_unless($this->allowed('preparation.update'), 403);

        $data = $this->validate([
            'sessionId' => ['required', 'integer'],
            'itemId' => ['required', 'integer'],
            'outputName' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unitSnapshot' => ['required', 'string', 'max:80'],
            'targetDivision' => ['required', 'in:processing,portioning,both'],
            'storageLocation' => ['nullable', 'string', 'max:255'],
            'expiresAt' => ['nullable', 'date'],
            'outputNotes' => ['nullable', 'string', 'max:2000'],
            'outputPhoto' => ['nullable', 'image', 'max:5120'],
        ]);

        $unit = $this->currentUnit();
        $session = PreparationSession::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->findOrFail($data['sessionId']);
        $item = $session->items()->findOrFail($data['itemId']);
        $photoPath = $this->outputPhoto
            ? $this->outputPhoto->store('preparation/outputs/'.today()->format('Y/m/d'), 'public')
            : null;

        try {
            $service->store($session, $item, auth()->user(), [
                'output_name' => $data['outputName'],
                'quantity' => $data['quantity'],
                'unit_snapshot' => $data['unitSnapshot'],
                'target_division' => $data['targetDivision'],
                'storage_location' => $data['storageLocation'],
                'expires_at' => $data['expiresAt'] ?: null,
                'photo_path' => $photoPath,
                'notes' => $data['outputNotes'],
            ]);
        } catch (\Throwable $exception) {
            if ($photoPath) {
                Storage::disk('public')->delete($photoPath);
            }
            throw $exception;
        }

        $this->reset([
            'itemId', 'outputName', 'quantity', 'unitSnapshot', 'storageLocation',
            'expiresAt', 'outputNotes', 'outputPhoto',
        ]);
        $this->targetDivision = 'processing';
        session()->flash('v3.status', 'Hasil Persiapan berhasil disimpan.');
    }

    public function requestWithdrawal(int $outputId, PreparationOutputService $service): void
    {
        $output = $this->output($outputId);
        $division = $this->resolveRequestDivision($outputId, $output);

        $service->requestWithdrawal($output, auth()->user(), [
            'destination_division' => $division,
            'requested_quantity' => $this->requestQuantities[$outputId] ?? null,
            'processing_batch_id' => $this->processingBatchIds[$outputId] ?? null,
            'portioning_session_id' => $this->portioningSessionIds[$outputId] ?? null,
            'notes' => $this->requestNotes[$outputId] ?? null,
        ]);

        unset(
            $this->requestQuantities[$outputId],
            $this->requestDivisions[$outputId],
            $this->processingBatchIds[$outputId],
            $this->portioningSessionIds[$outputId],
            $this->requestNotes[$outputId],
        );
        session()->flash('v3.status', 'Pengambilan dicatat dan menunggu verifikasi Persiapan.');
    }

    public function verifyWithdrawal(int $withdrawalId, PreparationOutputService $service): void
    {
        $withdrawal = $this->withdrawal($withdrawalId);
        $quantity = (float) ($this->verifiedQuantities[$withdrawalId] ?? $withdrawal->requested_quantity);
        $service->verify(
            $withdrawal,
            auth()->user(),
            $quantity,
            $this->reviewNotes[$withdrawalId] ?? null,
        );
        unset($this->verifiedQuantities[$withdrawalId], $this->reviewNotes[$withdrawalId]);
        session()->flash('v3.status', 'Pengambilan hasil Persiapan berhasil diverifikasi.');
    }

    public function rejectWithdrawal(int $withdrawalId, PreparationOutputService $service): void
    {
        $withdrawal = $this->withdrawal($withdrawalId);
        $service->reject(
            $withdrawal,
            auth()->user(),
            (string) ($this->reviewNotes[$withdrawalId] ?? ''),
        );
        unset($this->verifiedQuantities[$withdrawalId], $this->reviewNotes[$withdrawalId]);
        session()->flash('v3.status', 'Pengambilan ditolak dan stok hasil dikembalikan.');
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $sessions = PreparationSession::query()
            ->with('items')
            ->where('sppg_unit_id', $unit->getKey())
            ->whereIn('state', ['in_progress', 'completed'])
            ->latest('preparation_date')
            ->limit(30)
            ->get();

        $selectedItems = $this->sessionId
            ? $sessions->firstWhere('id', $this->sessionId)?->items ?? collect()
            : collect();

        $outputs = PreparationOutput::query()
            ->with([
                'session', 'sourceItem',
                'withdrawals.taker', 'withdrawals.verifier',
                'withdrawals.processingBatch', 'withdrawals.portioningSession',
            ])
            ->where('sppg_unit_id', $unit->getKey())
            ->latest('stored_at')
            ->latest('id')
            ->get();

        $processingBatches = ProcessingBatch::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->whereIn('state', [ProcessingBatchState::Planned->value, ProcessingBatchState::InProgress->value])
            ->latest('production_date')
            ->limit(50)
            ->get();
        $portioningSessions = PortioningSession::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->whereIn('state', [PortioningSessionState::Planned->value, PortioningSessionState::InProgress->value])
            ->latest('portioning_date')
            ->limit(50)
            ->get();

        return view('livewire.v3.preparation-outputs.index', [
            ...$this->shellData($unit),
            'sessions' => $sessions,
            'selectedItems' => $selectedItems,
            'outputs' => $outputs,
            'processingBatches' => $processingBatches,
            'portioningSessions' => $portioningSessions,
            'canCreate' => $this->allowed('preparation.update'),
            'canTakeProcessing' => $this->allowed('processing.update'),
            'canTakePortioning' => $this->allowed('portioning.update'),
            'canVerify' => $this->allowed('preparation.update'),
        ])->layout('layouts.v3', ['title' => 'Penyimpanan Hasil Persiapan']);
    }

    private function output(int $id): PreparationOutput
    {
        return PreparationOutput::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->findOrFail($id);
    }

    private function withdrawal(int $id): PreparationOutputWithdrawal
    {
        return PreparationOutputWithdrawal::query()
            ->whereHas('output', fn ($query) => $query->where('sppg_unit_id', $this->currentUnit()->getKey()))
            ->findOrFail($id);
    }

    private function resolveRequestDivision(int $outputId, PreparationOutput $output): string
    {
        $selected = (string) ($this->requestDivisions[$outputId] ?? '');
        if ($selected !== '') {
            return $selected;
        }

        if ($this->allowed('processing.update') && $output->isAvailableFor('processing')) {
            return 'processing';
        }

        if ($this->allowed('portioning.update') && $output->isAvailableFor('portioning')) {
            return 'portioning';
        }

        abort(403);
    }
}
