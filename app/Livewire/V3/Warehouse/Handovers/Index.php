<?php

namespace App\Livewire\V3\Warehouse\Handovers;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\PreparationMaterialHandover;
use App\Models\StockReceipt;
use App\Services\PreparationMaterialHandoverService;
use App\Support\V3\OperationsPresentation;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    public ?string $receiptId = null;

    public function mount(): void
    {
        $this->currentUnit();
        $this->authorizeView();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function createFromReceipt(PreparationMaterialHandoverService $service): void
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.create'), 403);
        $data = $this->validate([
            'receiptId' => ['required', Rule::exists('stock_receipts', 'id')->where(fn ($query) => $query
                ->where('sppg_unit_id', $unit->getKey())
                ->where('status', StockReceipt::STATUS_RECEIVED))],
        ]);
        $receipt = StockReceipt::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($data['receiptId']);
        $handover = $service->createFromStockReceipt($receipt);
        $this->redirectRoute('v3.warehouse.handovers.show', ['handover' => $handover], navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $this->authorizeView();
        $base = PreparationMaterialHandover::query()->where('sppg_unit_id', $unit->getKey());
        $query = (clone $base)->with('items')
            ->when(trim($this->search) !== '', function ($query): void {
                $search = trim($this->search);
                $query->where(fn ($query) => $query->where('handover_number', 'like', "%{$search}%")
                    ->orWhere('warehouse_officer_name', 'like', "%{$search}%")
                    ->orWhere('preparation_officer_name', 'like', "%{$search}%")
                    ->orWhereHas('items', fn ($query) => $query->where('ingredient_name_snapshot', 'like', "%{$search}%")));
            })
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status));

        return view('livewire.v3.warehouse.handovers.index', [
            ...$this->shellData($unit),
            'handovers' => $query->orderByDesc('handover_date')->orderByDesc('created_at')->paginate(12),
            'statuses' => OperationsPresentation::handoverStatuses(),
            'receivedReceipts' => StockReceipt::query()->where('sppg_unit_id', $unit->getKey())
                ->where('status', StockReceipt::STATUS_RECEIVED)->latest('receipt_date')->limit(50)->get(),
            'handoverCount' => (clone $base)->count(),
            'pendingCount' => (clone $base)->whereIn('status', [PreparationMaterialHandover::STATUS_DRAFT, PreparationMaterialHandover::STATUS_HANDED_OVER])->count(),
            'preparedCount' => (clone $base)->whereIn('status', [PreparationMaterialHandover::STATUS_PREPARED, PreparationMaterialHandover::STATUS_WASTE_RECORDED])->count(),
        ])->layout('layouts.v3', ['title' => 'Serah Bahan']);
    }

    private function authorizeView(): void
    {
        abort_unless($this->allowed('stock.view') || $this->allowed('preparation.view'), 403);
    }
}
