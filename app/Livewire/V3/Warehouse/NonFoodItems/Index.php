<?php

namespace App\Livewire\V3\Warehouse\NonFoodItems;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\MeasurementUnit;
use App\Models\NonFoodItem;
use App\Services\NonFoodProcurementService;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Throwable;

class Index extends Component
{
    use InteractsWithV3Shell;

    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';
    public string $category = 'Lainnya';
    public string $measurementUnitId = '';
    public string $minimumStock = '0';
    public string $targetStock = '0';
    public string $defaultLocation = 'Gudang Non-Pangan';
    public bool $tracksLot = false;
    public bool $tracksExpiry = false;
    public bool $isActive = true;
    public string $notes = '';
    public string $neededDate = '';
    public string $procurementNotes = '';
    /** @var array<int|string, string> */
    public array $quantities = [];
    public ?string $actionMessage = null;

    public function mount(): void
    {
        abort_unless($this->allowed('non_food_items.view'), 403);
        $this->neededDate = today()->addDay()->toDateString();
    }

    public function edit(int $id): void
    {
        abort_unless($this->allowed('non_food_items.manage'), 403);
        $item = NonFoodItem::query()->forUnit($this->currentUnit()->getKey())->findOrFail($id);
        $this->editingId = $item->id;
        $this->code = $item->code;
        $this->name = $item->name;
        $this->category = $item->category;
        $this->measurementUnitId = (string) $item->measurement_unit_id;
        $this->minimumStock = (string) $item->minimum_stock;
        $this->targetStock = (string) $item->target_stock;
        $this->defaultLocation = (string) $item->default_location;
        $this->tracksLot = $item->tracks_lot;
        $this->tracksExpiry = $item->tracks_expiry;
        $this->isActive = $item->is_active;
        $this->notes = (string) $item->notes;
    }

    public function save(): void
    {
        abort_unless($this->allowed('non_food_items.manage'), 403);
        $unitId = $this->currentUnit()->getKey();
        $data = $this->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('non_food_items', 'code')->where('sppg_unit_id', $unitId)->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(NonFoodItem::CATEGORIES)],
            'measurementUnitId' => ['required', 'integer', 'exists:measurement_units,id'],
            'minimumStock' => ['required', 'numeric', 'min:0'],
            'targetStock' => ['required', 'numeric', 'gte:minimumStock'],
            'defaultLocation' => ['nullable', 'string', 'max:255'],
            'tracksLot' => ['boolean'], 'tracksExpiry' => ['boolean'], 'isActive' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $values = [
            'sppg_unit_id' => $unitId, 'code' => trim($data['code']), 'name' => trim($data['name']),
            'category' => $data['category'], 'measurement_unit_id' => $data['measurementUnitId'],
            'minimum_stock' => $data['minimumStock'], 'target_stock' => $data['targetStock'],
            'default_location' => trim((string) $data['defaultLocation']) ?: null,
            'tracks_lot' => $data['tracksLot'], 'tracks_expiry' => $data['tracksExpiry'],
            'is_active' => $data['isActive'], 'notes' => trim((string) $data['notes']) ?: null,
        ];
        if ($this->editingId) {
            NonFoodItem::query()->forUnit($unitId)->findOrFail($this->editingId)->update($values);
        } else {
            NonFoodItem::query()->create($values);
        }
        $this->resetForm();
        $this->actionMessage = 'Master barang Non-Pangan berhasil disimpan.';
    }

    public function createProcurement(NonFoodProcurementService $service): void
    {
        abort_unless($this->allowed('non_food_procurement.create'), 403);
        $this->validate(['neededDate' => ['required', 'date', 'after_or_equal:today'], 'procurementNotes' => ['nullable', 'string', 'max:2000']]);
        try {
            $request = $service->createDraft($this->currentUnit()->getKey(), $this->neededDate, $this->quantities, $this->procurementNotes, auth()->user());
            session()->flash('v3.status', "Draft Non-Pangan {$request->request_number} berhasil dibuat.");
            $this->redirectRoute('v3.procurement.show', $request, navigate: true);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('quantities', $exception->getMessage());
        }
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $items = NonFoodItem::query()->with('measurementUnit')->forUnit($unit->getKey())->orderBy('name')->get();
        foreach ($items as $item) {
            $this->quantities[$item->id] ??= (string) $item->suggestedPurchaseQuantity();
        }
        return view('livewire.v3.warehouse.non-food-items.index', [
            ...$this->shellData($unit), 'items' => $items,
            'measurementUnits' => MeasurementUnit::query()->where('is_active', true)->orderBy('name')->get(),
            'categories' => NonFoodItem::CATEGORIES,
        ])->layout('layouts.v3', ['title' => 'Master & Kebutuhan Non-Pangan']);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'code', 'name', 'measurementUnitId', 'notes', 'tracksLot', 'tracksExpiry');
        $this->category = 'Lainnya'; $this->minimumStock = '0'; $this->targetStock = '0';
        $this->defaultLocation = 'Gudang Non-Pangan'; $this->isActive = true; $this->resetErrorBag();
    }
}
