<?php

namespace App\Livewire\V3\Warehouse\Receipts;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\ProcurementRequest;
use App\Models\StockReceipt;
use App\Services\StockReceiptService;
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

    public function createReceipt(StockReceiptService $service): void
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.create'), 403);
        $data = $this->validate([
            'procurementId' => ['required', Rule::exists('procurement_requests', 'id')->where(fn ($query) => $query
                ->where('sppg_unit_id', $unit->getKey())
                ->where('status', ProcurementRequest::STATUS_ORDERED))],
        ]);
        $request = ProcurementRequest::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($data['procurementId']);
        $receipt = $service->createFromProcurementRequest($request);
        $this->redirectRoute('v3.warehouse.receipts.show', ['receipt' => $receipt], navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
        $base = StockReceipt::query()->where('sppg_unit_id', $unit->getKey());
        $query = (clone $base)->with(['procurementRequest', 'items'])
            ->when(trim($this->search) !== '', function ($query): void {
                $search = trim($this->search);
                $query->where(fn ($query) => $query->where('receipt_number', 'like', "%{$search}%")
                    ->orWhereHas('procurementRequest', fn ($query) => $query->where('request_number', 'like', "%{$search}%"))
                    ->orWhereHas('items', fn ($query) => $query->where('ingredient_name_snapshot', 'like', "%{$search}%")));
            })
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status));

        return view('livewire.v3.warehouse.receipts.index', [
            ...$this->shellData($unit),
            'receipts' => $query->orderByDesc('receipt_date')->orderByDesc('created_at')->paginate(12),
            'statuses' => OperationsPresentation::receiptStatuses(),
            'orderedRequests' => ProcurementRequest::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->where('status', ProcurementRequest::STATUS_ORDERED)
                ->whereNotIn('id', StockReceipt::query()->select('procurement_request_id')->whereNotNull('procurement_request_id'))
                ->latest('ordered_at')->limit(50)->get(),
            'receiptCount' => (clone $base)->count(),
            'draftCount' => (clone $base)->where('status', StockReceipt::STATUS_DRAFT)->count(),
            'receivedCount' => (clone $base)->where('status', StockReceipt::STATUS_RECEIVED)->count(),
        ])->layout('layouts.v3', ['title' => 'Penerimaan Bahan']);
    }
}
