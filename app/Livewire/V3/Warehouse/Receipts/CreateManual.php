<?php

namespace App\Livewire\V3\Warehouse\Receipts;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\Ingredient;
use App\Models\NonFoodItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\StockReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class CreateManual extends Component
{
    use InteractsWithV3Shell, WithFileUploads;

    #[Url(as: 'gudang', history: true)]
    public string $warehouseType = Warehouse::TYPE_FOOD;

    public string $receiptDate = '';

    public string $supplierId = '';

    public string $notes = '';

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function mount(): void
    {
        abort_unless($this->allowed('stock.create'), 403);
        $this->receiptDate = today()->toDateString();
        $this->addRow();
    }

    public function addRow(): void
    {
        $this->rows[] = [
            'ingredient_id' => '',
            'non_food_item_id' => '',
            'received_quantity' => '',
            'accepted_quantity' => '',
            'rejected_quantity' => 0,
            'supplier_batch_number' => '',
            'expired_date' => '',
            'received_temperature_celsius' => '',
            'quality_notes' => '',
            'photos' => [],
        ];
    }

    public function removeRow(int $index): void
    {
        if (count($this->rows) <= 1) {
            return;
        }
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
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

    public function save(StockReceiptService $service): void
    {
        abort_unless($this->allowed('stock.create'), 403);
        $unit = $this->currentUnit();
        $warehouse = Warehouse::forUnit($unit->getKey(), $this->warehouseType);
        $itemField = $this->warehouseType === Warehouse::TYPE_NON_FOOD ? 'non_food_item_id' : 'ingredient_id';
        $itemTable = $this->warehouseType === Warehouse::TYPE_NON_FOOD ? 'non_food_items' : 'ingredients';

        $data = $this->validate([
            'receiptDate' => ['required', 'date', 'before_or_equal:today'],
            'supplierId' => ['required', Rule::exists('suppliers', 'id')->where(fn ($query) => $query
                ->where('sppg_unit_id', $unit->getKey())->where('is_active', true))],
            'notes' => ['nullable', 'string', 'max:3000'],
            'rows' => ['required', 'array', 'min:1', 'max:100'],
            "rows.*.{$itemField}" => ['required', 'integer', Rule::exists($itemTable, 'id')->where(fn ($query) => $query
                ->where('sppg_unit_id', $unit->getKey())->where('is_active', true))],
            'rows.*.received_quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'rows.*.accepted_quantity' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'rows.*.rejected_quantity' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'rows.*.supplier_batch_number' => ['nullable', 'string', 'max:100'],
            'rows.*.expired_date' => ['nullable', 'date'],
            'rows.*.received_temperature_celsius' => ['nullable', 'numeric', 'between:-50,100'],
            'rows.*.quality_notes' => ['nullable', 'string', 'max:1000'],
            'rows.*.photos' => ['required', 'array', 'min:1', 'max:10'],
            'rows.*.photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [], [
            'receiptDate' => 'tanggal penerimaan',
            'supplierId' => 'supplier',
            "rows.*.{$itemField}" => 'barang',
            'rows.*.received_quantity' => 'jumlah diterima',
            'rows.*.accepted_quantity' => 'jumlah baik',
            'rows.*.rejected_quantity' => 'jumlah ditolak',
            'rows.*.photos' => 'dokumentasi barang',
        ]);

        foreach ($data['rows'] as $index => $row) {
            if (abs(((float) $row['accepted_quantity'] + (float) $row['rejected_quantity']) - (float) $row['received_quantity']) > 0.0001) {
                throw ValidationException::withMessages([
                    "rows.{$index}.accepted_quantity" => 'Jumlah baik + ditolak harus sama dengan jumlah diterima.',
                ]);
            }
        }

        $storedPaths = [];
        try {
            $receipt = DB::transaction(function () use ($service, $unit, $warehouse, $data, &$storedPaths) {
                $receipt = $service->createManual(
                    $unit->getKey(),
                    $warehouse->getKey(),
                    (int) $data['supplierId'],
                    $data['receiptDate'],
                    $data['notes'] ?? null,
                    $data['rows'],
                    auth()->user(),
                );

                foreach ($receipt->items->values() as $index => $item) {
                    foreach ($data['rows'][$index]['photos'] as $photo) {
                        $path = $photo->store(
                            'stock-receipts/'.$receipt->receipt_date->format('Y/m/d').'/items/'.$item->getKey(),
                            'public',
                        );
                        $storedPaths[] = $path;
                        $item->photos()->create([
                            'stock_receipt_id' => $receipt->getKey(),
                            'item_name_snapshot' => $item->ingredient_name_snapshot,
                            'photo_path' => $path,
                            'original_name' => $photo->getClientOriginalName(),
                            'uploaded_by' => auth()->id(),
                        ]);
                    }
                }

                return $receipt;
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }

        session()->flash('v3.status', 'Draft penerimaan manual berhasil dibuat. Periksa kembali lalu masukkan barang baik ke stok.');
        $this->redirectRoute('v3.warehouse.receipts.show', ['receipt' => $receipt], navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.create'), 403);
        $items = $this->warehouseType === Warehouse::TYPE_NON_FOOD
            ? NonFoodItem::query()->with('measurementUnit')->forUnit($unit->getKey())->where('is_active', true)->orderBy('name')->get()
            : Ingredient::query()->with('measurementUnit')->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->get();

        return view('livewire.v3.warehouse.receipts.create-manual', [
            ...$this->shellData($unit),
            'suppliers' => Supplier::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->get(),
            'itemSearchOptions' => $items->map(fn ($item): array => [
                'id' => (string) $item->getKey(),
                'label' => trim($item->name.' · '.($item->code ?: 'tanpa kode').' · '.($item->measurementUnit?->symbol ?: $item->measurementUnit?->code ?: '-')),
            ])->values()->all(),
            'itemUnits' => $items->mapWithKeys(fn ($item): array => [
                $item->getKey() => $item->measurementUnit?->symbol ?: $item->measurementUnit?->code ?: 'unit',
            ])->all(),
        ])->layout('layouts.v3', ['title' => 'Penerimaan Manual']);
    }
}
