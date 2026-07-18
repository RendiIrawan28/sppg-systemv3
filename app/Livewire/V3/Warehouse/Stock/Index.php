<?php

namespace App\Livewire\V3\Warehouse\Stock;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\StockMovement;
use App\Support\V3\OperationsPresentation;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(as: 'jenis', history: true)]
    public string $type = 'all';

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
        $base = StockMovement::query()->where('sppg_unit_id', $unit->getKey());
        $movements = (clone $base)
            ->when(trim($this->search) !== '', function ($query): void {
                $search = trim($this->search);
                $query->where(fn ($query) => $query->where('ingredient_name_snapshot', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhere('supplier_batch_number', 'like', "%{$search}%"));
            })
            ->when($this->type !== 'all', fn ($query) => $query->where('movement_type', $this->type));
        $balances = StockMovement::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->select([
                'ingredient_id',
                'ingredient_name_snapshot',
                'unit_snapshot',
                DB::raw('SUM(quantity_in_kg - quantity_out_kg) AS balance_kg'),
                DB::raw('SUM(quantity_in_kg) AS total_in_kg'),
                DB::raw('SUM(quantity_out_kg) AS total_out_kg'),
                DB::raw('MAX(movement_date) AS last_movement_date'),
            ])
            ->groupBy('ingredient_id', 'ingredient_name_snapshot', 'unit_snapshot')
            ->orderBy('ingredient_name_snapshot')
            ->get();

        return view('livewire.v3.warehouse.stock.index', [
            ...$this->shellData($unit),
            'balances' => $balances,
            'movements' => $movements->orderByDesc('movement_date')->orderByDesc('created_at')->paginate(15),
            'types' => OperationsPresentation::movementTypes(),
            'totalBalance' => (float) $balances->sum('balance_kg'),
            'ingredientCount' => $balances->count(),
            'incoming' => (float) (clone $base)->sum('quantity_in_kg'),
            'outgoing' => (float) (clone $base)->sum('quantity_out_kg'),
        ])->layout('layouts.v3', ['title' => 'Kartu Stok']);
    }
}
