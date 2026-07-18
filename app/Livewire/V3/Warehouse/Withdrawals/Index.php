<?php

namespace App\Livewire\V3\Warehouse\Controls;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\InventoryLot;
use App\Models\StockAdjustment;
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

    public function mount(): void { $this->currentUnit(); abort_unless($this->allowed('stock.view'), 403); }
    public function createAdjustment(StockControlService $service): void
    {
        abort_unless($this->allowed('stock.update'), 403);
        $data = $this->validate(['lotId' => ['required', 'integer'], 'actualQuantity' => ['required', 'numeric', 'min:0'], 'type' => ['required', 'in:stock_opname,return_from_division,damage'], 'reason' => ['required', 'string', 'max:2000']]);
        $lot = InventoryLot::where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($data['lotId']);
        $service->create($lot, (float) $data['actualQuantity'], $data['type'], $data['reason'], auth()->user());
        $this->reset('lotId', 'actualQuantity', 'reason'); session()->flash('v3.status', 'Penyesuaian dibuat dan menunggu verifikasi.');
    }
    public function saveLot(int $id, StockControlService $service): void
    {
        abort_unless($this->allowed('stock.update'), 403);
        $lot = InventoryLot::where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
        $service->updateLot($lot, $this->locations[$id] ?? '', $this->statuses[$id] ?? $lot->status);
        session()->flash('v3.status', 'Lokasi dan status lot diperbarui.');
    }
    public function verifyAdjustment(int $id, StockControlService $service): void
    {
        abort_unless($this->allowed('stock.approve'), 403);
        $adjustment = StockAdjustment::where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
        $service->verify($adjustment, auth()->user()); session()->flash('v3.status', 'Penyesuaian diverifikasi dan kartu stok diperbarui.');
    }
    public function render()
    {
        $unit = $this->currentUnit();
        $lots = InventoryLot::with('ingredient')->where('sppg_unit_id', $unit->id)->orderByRaw('expired_date IS NULL')->orderBy('expired_date')->get();
        foreach ($lots as $lot) { $this->locations[$lot->id] ??= (string) $lot->location_name; $this->statuses[$lot->id] ??= $lot->status; }
        return view('livewire.v3.warehouse.controls.index', [...$this->shellData($unit), 'lots' => $lots,
            'adjustments' => StockAdjustment::with(['lot.ingredient','creator'])->where('sppg_unit_id', $unit->id)->latest()->limit(50)->get(),
            'canEdit' => $this->allowed('stock.update'), 'canApprove' => $this->allowed('stock.approve')])
            ->layout('layouts.v3', ['title' => 'Kontrol Stok']);
    }
}