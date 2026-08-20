<?php

namespace App\Livewire\V3\Warehouse\Stock;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\InventoryLot;
use App\Models\StockMovement;
use App\Models\WarehouseWithdrawal;
use App\Models\Warehouse;
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

    #[Url(as: 'gudang', history: true)]
    public string $warehouseType = Warehouse::TYPE_FOOD;

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

    public function updatedWarehouseType(): void
    {
        if (! in_array($this->warehouseType, [Warehouse::TYPE_FOOD, Warehouse::TYPE_NON_FOOD], true)) {
            $this->warehouseType = Warehouse::TYPE_FOOD;
        }
        $this->resetPage();
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
        $warehouse = Warehouse::forUnit($unit->getKey(), $this->warehouseType);
        $base = StockMovement::query()->where('sppg_unit_id', $unit->getKey())->where('warehouse_id', $warehouse->getKey());
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
            ->where('warehouse_id', $warehouse->getKey())
            ->select([
                'ingredient_id',
                'non_food_item_id',
                'ingredient_name_snapshot',
                'unit_snapshot',
                DB::raw('SUM(quantity_in - quantity_out) AS balance_quantity'),
                DB::raw('SUM(quantity_in) AS total_in'),
                DB::raw('SUM(quantity_out) AS total_out'),
                DB::raw('MAX(movement_date) AS last_movement_date'),
            ])
            ->groupBy('ingredient_id', 'non_food_item_id', 'ingredient_name_snapshot', 'unit_snapshot')
            ->orderBy('ingredient_name_snapshot')
            ->get();
        $lots = InventoryLot::query()->with(['ingredient', 'nonFoodItem'])
            ->where('sppg_unit_id', $unit->getKey())->where('warehouse_id', $warehouse->getKey())->where('balance_quantity', '>', 0)
            ->orderByRaw('expired_date IS NULL')->orderBy('expired_date')->limit(100)->get();
        $pendingCount = DB::table('warehouse_withdrawals')->where('sppg_unit_id', $unit->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->where('status', WarehouseWithdrawal::WAITING)->count();

        return view('livewire.v3.warehouse.stock.index', [
            ...$this->shellData($unit),
            'balances' => $balances,
            'lots' => $lots,
            'movements' => $movements->orderByDesc('movement_date')->orderByDesc('created_at')->paginate(15),
            'types' => OperationsPresentation::movementTypes(),
            'ingredientCount' => $balances->count(),
            'unitCount' => $balances->pluck('unit_snapshot')->filter()->unique()->count(),
            'activeLotCount' => $lots->count(),
            'pendingCount' => $pendingCount,
            'warehouse' => $warehouse,
        ])->layout('layouts.v3', ['title' => 'Kartu Stok']);
    }
}
