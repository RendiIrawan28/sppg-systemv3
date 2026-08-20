<?php

namespace App\Livewire\V3\Warehouse\OpeningStocks;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\NonFoodItem;
use App\Models\OpeningStock;
use App\Models\Warehouse;
use App\Services\OpeningStockService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    #[Url(as: 'gudang', history: true)]
    public string $warehouseType = Warehouse::TYPE_FOOD;

    public string $openingDate = '';

    public string $notes = '';

    public ?TemporaryUploadedFile $photo = null;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public ?string $actionMessage = null;

    public function mount(): void
    {
        abort_unless($this->allowed('stock.create'), 403);
        $this->openingDate = today()->toDateString();
        $this->addRow();
    }

    public function addRow(): void
    {
        $this->rows[] = [
            'mode' => 'existing',
            'ingredient_id' => '',
            'non_food_item_id' => '',
            'new_name' => '',
            'new_category' => $this->warehouseType === Warehouse::TYPE_NON_FOOD ? 'Lainnya' : 'other',
            'measurement_unit_id' => '',
            'quantity' => '',
            'lot_number' => '',
            'expired_date' => '',
            'storage_type' => 'dry',
            'location_name' => $this->warehouseType === Warehouse::TYPE_NON_FOOD ? 'Gudang Non-Pangan' : 'Gudang Utama',
            'condition_notes' => '',
            'minimum_stock' => 0,
            'target_stock' => 0,
            'tracks_lot' => false,
            'tracks_expiry' => false,
        ];
    }

    public function updatedWarehouseType(): void
    {
        if (! in_array($this->warehouseType, [Warehouse::TYPE_FOOD, Warehouse::TYPE_NON_FOOD], true)) {
            $this->warehouseType = Warehouse::TYPE_FOOD;
        }
        $this->rows = [];
        $this->addRow();
        $this->resetErrorBag();
    }

    public function removeRow(int $index): void
    {
        if (count($this->rows) === 1) {
            return;
        }

        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function save(OpeningStockService $service): void
    {
        abort_unless($this->allowed('stock.create'), 403);
        $data = $this->validate([
            'openingDate' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            'rows.*.mode' => ['required', Rule::in(['existing', 'new'])],
            'rows.*.ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'rows.*.non_food_item_id' => ['nullable', 'integer', 'exists:non_food_items,id'],
            'rows.*.new_name' => ['nullable', 'string', 'max:255'],
            'rows.*.new_category' => ['nullable', Rule::in(array_keys($this->categories()))],
            'rows.*.measurement_unit_id' => ['nullable', 'integer', 'exists:measurement_units,id'],
            'rows.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'rows.*.lot_number' => ['nullable', 'string', 'max:100'],
            'rows.*.expired_date' => ['nullable', 'date'],
            'rows.*.storage_type' => ['required', Rule::in(['dry', 'wet', 'freezer', 'chiller'])],
            'rows.*.location_name' => ['nullable', 'string', 'max:255'],
            'rows.*.condition_notes' => ['nullable', 'string', 'max:2000'],
            'rows.*.minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'rows.*.target_stock' => ['nullable', 'numeric', 'min:0'],
            'rows.*.tracks_lot' => ['boolean'],
            'rows.*.tracks_expiry' => ['boolean'],
        ], [], [
            'openingDate' => 'tanggal stok awal',
            'photo' => 'foto dokumentasi',
            'rows.*.ingredient_id' => 'barang',
            'rows.*.new_name' => 'nama barang baru',
            'rows.*.measurement_unit_id' => 'satuan barang baru',
            'rows.*.quantity' => 'jumlah',
            'rows.*.expired_date' => 'tanggal kedaluwarsa',
        ]);

        $this->validateRows($data['rows']);
        $path = $this->photo?->store('v3/warehouse/opening-stocks', 'public');

        try {
            $opening = $service->createForWarehouse(
                $this->currentUnit()->getKey(),
                $data['openingDate'],
                $path,
                $data['notes'] ?? null,
                $data['rows'],
                auth()->user(),
                $this->warehouseType,
            );
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }

        $this->reset('notes', 'photo');
        $this->rows = [];
        $this->addRow();
        $this->resetErrorBag();
        $this->actionMessage = "Stok awal {$opening->opening_number} langsung aktif dan kartu stok telah diperbarui.";
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.create'), 403);
        $warehouse = Warehouse::forUnit($unit->getKey(), $this->warehouseType);
        $ingredients = $this->warehouseType === Warehouse::TYPE_NON_FOOD
            ? NonFoodItem::query()->with('measurementUnit')->forUnit($unit->getKey())->where('is_active', true)->orderBy('name')->get()
            : Ingredient::query()->with('measurementUnit')->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->get();

        return view('livewire.v3.warehouse.opening-stocks.index', [
            ...$this->shellData($unit),
            'ingredients' => $ingredients,
            'ingredientUnits' => $ingredients->mapWithKeys(fn ($ingredient): array => [
                $ingredient->getKey() => $ingredient->measurementUnit?->symbol ?: $ingredient->measurementUnit?->code ?: '-',
            ])->all(),
            'ingredientSearchOptions' => $ingredients->map(fn ($ingredient): array => [
                'id' => (string) $ingredient->getKey(),
                'label' => trim($ingredient->name.' · '.$ingredient->code.' · '.($ingredient->measurementUnit?->symbol ?: $ingredient->measurementUnit?->code ?: '-')),
            ])->values()->all(),
            'measurementUnits' => MeasurementUnit::query()->where('is_active', true)->orderBy('name')->get(),
            'categories' => $this->categories(),
            'storageTypes' => ['dry' => 'Gudang kering', 'wet' => 'Gudang basah', 'freezer' => 'Freezer', 'chiller' => 'Chiller'],
            'recentOpenings' => OpeningStock::query()
                ->with(['items', 'creator'])
                ->where('sppg_unit_id', $unit->getKey())
                ->where('warehouse_id', $warehouse->getKey())
                ->latest('opening_date')
                ->latest('id')
                ->limit(20)
                ->get(),
        ])->layout('layouts.v3', ['title' => 'Input Stok Awal']);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function validateRows(array $rows): void
    {
        foreach ($rows as $index => $row) {
            $itemKey = $this->warehouseType === Warehouse::TYPE_NON_FOOD ? 'non_food_item_id' : 'ingredient_id';
            if ($row['mode'] === 'existing' && blank($row[$itemKey] ?? null)) {
                throw ValidationException::withMessages(["rows.{$index}.{$itemKey}" => 'Pilih barang dari master yang sesuai.']);
            }
            if ($row['mode'] === 'new' && (blank($row['new_name']) || blank($row['measurement_unit_id']))) {
                throw ValidationException::withMessages(["rows.{$index}.new_name" => 'Nama dan satuan barang baru wajib diisi.']);
            }
        }
    }

    /** @return array<string, string> */
    private function categories(): array
    {
        if ($this->warehouseType === Warehouse::TYPE_NON_FOOD) {
            return array_combine(NonFoodItem::CATEGORIES, NonFoodItem::CATEGORIES);
        }

        return [
            'staple' => 'Makanan Pokok', 'animal_protein' => 'Protein Hewani', 'plant_protein' => 'Protein Nabati',
            'vegetable' => 'Sayuran', 'fruit' => 'Buah', 'seasoning' => 'Bumbu', 'oil' => 'Minyak dan Lemak',
            'drink' => 'Minuman', 'dairy' => 'Susu dan Olahan', 'processed' => 'Bahan Olahan', 'other' => 'Lainnya',
        ];
    }
}
