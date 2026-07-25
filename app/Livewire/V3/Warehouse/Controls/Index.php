<?php

namespace App\Livewire\V3\Warehouse\Controls;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\InventoryLot;
use App\Models\PreparationReturn;
use App\Models\ProcessingReturn;
use App\Models\StockAdjustment;
use App\Services\PreparationReturnService;
use App\Services\ProcessingReturnService;
use App\Services\StockControlService;
use Livewire\Component;

class Index extends Component
{
    use InteractsWithV3Shell;

    public string $lotId = '';

    public string $actualQuantity = '';

    public string $type = 'stock_opname';

    public string $reason = '';

    public array $locations = [];

    public array $statuses = [];

    public array $storageTypes = [];

    public array $returnActualQuantities = [];

    public array $returnDispositions = [];

    public array $returnNotes = [];

    public array $processingReturnActualQuantities = [];

    public array $processingReturnDispositions = [];

    public array $processingReturnNotes = [];

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
    }

    public function createAdjustment(StockControlService $service): void
    {
        abort_unless($this->allowed('stock.update'), 403);
        $data = $this->validate(['lotId' => ['required', 'integer'], 'actualQuantity' => ['required', 'numeric', 'min:0'], 'type' => ['required', 'in:stock_opname,return_from_division,damage'], 'reason' => ['required', 'string', 'max:2000']]);
        $lot = InventoryLot::where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($data['lotId']);
        $service->create($lot, (float) $data['actualQuantity'], $data['type'], $data['reason'], auth()->user());
        $this->reset('lotId', 'actualQuantity', 'reason');
        session()->flash('v3.status', 'Penyesuaian dibuat dan menunggu verifikasi.');
    }

    public function saveLot(int $id, StockControlService $service): void
    {
        abort_unless($this->allowed('stock.update'), 403);
        $lot = InventoryLot::where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
        $service->updateLot($lot, $this->locations[$id] ?? '', $this->storageTypes[$id] ?? $lot->storage_type, $this->statuses[$id] ?? $lot->status);
        session()->flash('v3.status', 'Lokasi dan status lot diperbarui.');
    }

    public function verifyAdjustment(int $id, StockControlService $service): void
    {
        abort_unless($this->allowed('stock.approve'), 403);
        $adjustment = StockAdjustment::where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
        $service->verify($adjustment, auth()->user());
        session()->flash('v3.status', 'Penyesuaian diverifikasi dan kartu stok diperbarui.');
    }

    public function verifyReturn(int $id, PreparationReturnService $service): void
    {
        abort_unless($this->allowed('stock.approve'), 403);
        $return = PreparationReturn::query()->where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
        $this->validate([
            "returnActualQuantities.$id" => ['required', 'numeric', 'gt:0'],
            "returnDispositions.$id" => ['required', 'in:available,quarantine,rejected'],
            "returnNotes.$id" => ['nullable', 'string', 'max:2000'],
        ]);
        $service->verify(
            $return,
            (float) $this->returnActualQuantities[$id],
            $this->returnDispositions[$id],
            $this->returnNotes[$id] ?? null,
            auth()->user(),
        );
        session()->flash('v3.status', 'Retur diverifikasi dan kartu stok diperbarui.');
    }

    public function rejectReturn(int $id, PreparationReturnService $service): void
    {
        abort_unless($this->allowed('stock.approve'), 403);
        $this->validate(["returnNotes.$id" => ['required', 'string', 'max:2000']]);
        $return = PreparationReturn::query()->where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
        $service->reject($return, $this->returnNotes[$id], auth()->user());
        session()->flash('v3.status', 'Retur ditolak tanpa mengubah stok.');
    }

    public function verifyProcessingReturn(int $id, ProcessingReturnService $service): void
    {
        abort_unless($this->allowed('stock.approve'), 403);
        $return = ProcessingReturn::query()
            ->where('sppg_unit_id', $this->currentUnit()->id)
            ->findOrFail($id);
        $this->validate([
            "processingReturnActualQuantities.$id" => ['required', 'numeric', 'gt:0'],
            "processingReturnDispositions.$id" => ['required', 'in:available,quarantine,rejected'],
            "processingReturnNotes.$id" => ['nullable', 'string', 'max:2000'],
        ]);
        $service->verify(
            $return,
            (float) $this->processingReturnActualQuantities[$id],
            $this->processingReturnDispositions[$id],
            $this->processingReturnNotes[$id] ?? null,
            auth()->user(),
        );
        session()->flash('v3.status', 'Retur Pengolahan diverifikasi dan kartu stok diperbarui.');
    }

    public function rejectProcessingReturn(int $id, ProcessingReturnService $service): void
    {
        abort_unless($this->allowed('stock.approve'), 403);
        $this->validate([
            "processingReturnNotes.$id" => ['required', 'string', 'max:2000'],
        ]);
        $return = ProcessingReturn::query()
            ->where('sppg_unit_id', $this->currentUnit()->id)
            ->findOrFail($id);
        $service->reject($return, $this->processingReturnNotes[$id], auth()->user());
        session()->flash('v3.status', 'Retur Pengolahan ditolak tanpa mengubah stok.');
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $lots = InventoryLot::with('ingredient')->where('sppg_unit_id', $unit->id)->orderByRaw('expired_date IS NULL')->orderBy('expired_date')->get();
        foreach ($lots as $lot) {
            $this->locations[$lot->id] ??= (string) $lot->location_name;
            $this->storageTypes[$lot->id] ??= $lot->storage_type;
            $this->statuses[$lot->id] ??= $lot->status;
        }
        $returns = PreparationReturn::query()
            ->with(['session', 'returner', 'sourceLot'])
            ->where('sppg_unit_id', $unit->id)
            ->latest()
            ->limit(50)
            ->get();
        foreach ($returns->where('status', PreparationReturn::WAITING) as $return) {
            $this->returnActualQuantities[$return->id] ??= (string) $return->requested_quantity;
            $this->returnDispositions[$return->id] ??= $return->condition_status === 'good' ? 'available' : 'quarantine';
        }
        $processingReturns = ProcessingReturn::query()
            ->with(['batch', 'returner', 'sourceLot'])
            ->where('sppg_unit_id', $unit->id)
            ->latest()
            ->limit(50)
            ->get();
        foreach ($processingReturns->where('status', ProcessingReturn::WAITING) as $return) {
            $this->processingReturnActualQuantities[$return->id] ??= (string) $return->requested_quantity;
            $this->processingReturnDispositions[$return->id] ??= $return->condition_status === 'good'
                ? 'available'
                : 'quarantine';
        }

        return view('livewire.v3.warehouse.controls.index', [...$this->shellData($unit), 'lots' => $lots,
            'returns' => $returns,
            'processingReturns' => $processingReturns,
            'adjustments' => StockAdjustment::with(['lot.ingredient', 'creator'])->where('sppg_unit_id', $unit->id)->latest()->limit(50)->get(),
            'canEdit' => $this->allowed('stock.update'), 'canApprove' => $this->allowed('stock.approve')])
            ->layout('layouts.v3', ['title' => 'Kontrol Stok']);
    }
}
