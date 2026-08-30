<?php

namespace App\Livewire\V3\Warehouse\Receipts;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Livewire\V3\Concerns\FiltersByWorkDate;
use App\Models\ProcurementRequest;
use App\Models\StockReceipt;
use App\Models\Warehouse;
use App\Services\StockReceiptService;
use App\Support\V3\OperationsPresentation;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithV3Shell;
    use FiltersByWorkDate;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    #[Url(as: 'gudang', history: true)]
    public string $warehouseType = Warehouse::TYPE_FOOD;

    public ?string $procurementId = null;

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedWarehouseType(): void
    {
        abort_unless(in_array($this->warehouseType, [Warehouse::TYPE_FOOD, Warehouse::TYPE_NON_FOOD], true), 404);
        $this->procurementId = null;
        $this->resetPage();
    }

    public function createReceipt(StockReceiptService $service): void
    {
        $unit = $this->currentUnit();
        $warehouse = Warehouse::forUnit($unit->getKey(), $this->warehouseType);
        abort_unless($this->allowed('stock.create'), 403);
        $data = $this->validate([
            'procurementId' => ['required', Rule::exists('procurement_requests', 'id')->where(fn ($query) => $query
                ->where('sppg_unit_id', $unit->getKey())
                ->where('warehouse_id', $warehouse->getKey())
                ->where('status', ProcurementRequest::STATUS_ORDERED))],
        ]);
        $request = ProcurementRequest::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->findOrFail($data['procurementId']);
        $receipts = $service->createGroupedFromProcurementRequest($request);

        if ($receipts->count() === 1) {
            $this->redirectRoute('v3.warehouse.receipts.show', ['receipt' => $receipts->first()], navigate: true);

            return;
        }

        session()->flash('v3.status', "{$receipts->count()} dokumen penerimaan dibuat berdasarkan supplier.");
        $this->redirectRoute('v3.warehouse.receipts.index', navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
        abort_unless(in_array($this->warehouseType, [Warehouse::TYPE_FOOD, Warehouse::TYPE_NON_FOOD], true), 404);
        $warehouse = Warehouse::forUnit($unit->getKey(), $this->warehouseType);
        $base = StockReceipt::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->whereDate('receipt_date', $this->selectedWorkDate());
        $query = (clone $base)->with(['procurementRequest', 'supplier', 'items'])
            ->when(trim($this->search) !== '', function ($query): void {
                $search = trim($this->search);
                $query->where(fn ($query) => $query->where('receipt_number', 'like', "%{$search}%")
                    ->orWhereHas('procurementRequest', fn ($query) => $query->where('request_number', 'like', "%{$search}%"))
                    ->orWhereHas('supplier', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('items', fn ($query) => $query->where('ingredient_name_snapshot', 'like', "%{$search}%")));
            })
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status));

        return view('livewire.v3.warehouse.receipts.index', [
            ...$this->shellData($unit),
            'receipts' => $query->orderByDesc('receipt_date')->orderByDesc('created_at')->paginate(12),
            'statuses' => OperationsPresentation::receiptStatuses(),
            'orderedRequests' => ProcurementRequest::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->where('warehouse_id', $warehouse->getKey())
                ->where('status', ProcurementRequest::STATUS_ORDERED)
                ->whereNotIn('id', StockReceipt::query()->select('procurement_request_id')->whereNotNull('procurement_request_id'))
                ->latest('ordered_at')->limit(50)->get(),
            'receiptCount' => (clone $base)->count(),
            'draftCount' => (clone $base)->where('status', StockReceipt::STATUS_DRAFT)->count(),
            'receivedCount' => (clone $base)->where('status', StockReceipt::STATUS_RECEIVED)->count(),
            'warehouse' => $warehouse,
        ])->layout('layouts.v3', ['title' => 'Penerimaan Bahan']);
    }
}
