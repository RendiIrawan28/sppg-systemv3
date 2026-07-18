<?php

namespace App\Livewire\V3\Procurement;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\ProcurementRequest;
use App\Support\V3\OperationsPresentation;
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

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('procurement.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('procurement.view'), 403);

        $base = ProcurementRequest::query()->where('sppg_unit_id', $unit->getKey());
        $query = (clone $base)->with(['nutritionRequirementPlan', 'items.supplier'])
            ->when(trim($this->search) !== '', function ($query): void {
                $search = trim($this->search);
                $query->where(fn ($query) => $query
                    ->where('request_number', 'like', "%{$search}%")
                    ->orWhereHas('nutritionRequirementPlan', fn ($query) => $query->where('plan_number', 'like', "%{$search}%"))
                    ->orWhereHas('items', fn ($query) => $query->where('ingredient_name_snapshot', 'like', "%{$search}%")));
            })
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status));

        return view('livewire.v3.procurement.index', [
            ...$this->shellData($unit),
            'requests' => $query->orderByDesc('request_date')->orderByDesc('created_at')->paginate(12),
            'statuses' => OperationsPresentation::procurementStatuses(),
            'requestCount' => (clone $base)->count(),
            'waitingCount' => (clone $base)->whereIn('status', [
                ProcurementRequest::STATUS_SUBMITTED,
                ProcurementRequest::STATUS_FINANCE_VERIFIED,
                ProcurementRequest::STATUS_APPROVED,
            ])->count(),
            'orderedCount' => (clone $base)->where('status', ProcurementRequest::STATUS_ORDERED)->count(),
            'totalAmount' => (float) (clone $base)->sum('estimated_total_amount'),
        ])->layout('layouts.v3', ['title' => 'Pengadaan Bahan']);
    }
}
