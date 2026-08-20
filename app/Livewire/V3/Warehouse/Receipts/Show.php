<?php

namespace App\Livewire\V3\Warehouse\Receipts;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\StockReceipt;
use App\Models\StockReceiptItemPhoto;
use App\Models\Warehouse;
use App\Services\StockReceiptService;
use App\Support\V3\OperationsPresentation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class Show extends Component
{
    use InteractsWithV3Shell, WithFileUploads;

    public int $receiptId;

    public string $receiptDate = '';

    public string $notes = '';

    /** @var array<int, array<int, mixed>> */
    public array $itemDocumentations = [];

    public ?string $actionMessage = null;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function mount(StockReceipt $receipt): void
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
        abort_unless((int) $receipt->sppg_unit_id === (int) $unit->getKey(), 404);
        $this->receiptId = $receipt->getKey();
        $this->fillFromReceipt($receipt);
    }

    public function save(): void
    {
        $this->runAction(function (): string {
            $this->persist();

            return 'Data penerimaan dan QC berhasil disimpan.';
        });
    }

    public function receive(StockReceiptService $service): void
    {
        abort_unless($this->allowed('stock.create'), 403);
        $this->runAction(function () use ($service): string {
            $this->persist();
            $service->receive($this->receipt());

            return 'Bahan diterima; kuantitas lolos QC telah masuk ke kartu stok.';
        });
    }

    public function delete(): void
    {
        abort_unless($this->allowed('stock.update') || $this->allowed('stock.create'), 403);
        $receipt = $this->receipt();
        abort_unless($receipt->isEditable(), 403);
        $receipt->delete();
        session()->flash('v3.status', 'Draft penerimaan berhasil dihapus.');
        $this->redirectRoute('v3.warehouse.receipts.index', navigate: true);
    }

    public function deleteItemPhoto(int $photoId): void
    {
        $receipt = $this->receipt();
        abort_unless($receipt->isEditable() && ($this->allowed('stock.update') || $this->allowed('stock.create')), 403);
        $photo = StockReceiptItemPhoto::query()
            ->where('stock_receipt_id', $receipt->getKey())
            ->findOrFail($photoId);
        Storage::disk('public')->delete($photo->photo_path);
        $photo->delete();
        $this->actionMessage = 'Foto dokumentasi barang berhasil dihapus.';
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
        $receipt = $this->receipt()->load(['warehouse', 'procurementRequest', 'supplier', 'items.supplier', 'items.nonFoodItem', 'items.photos']);

        return view('livewire.v3.warehouse.receipts.show', [
            ...$this->shellData($unit),
            'receipt' => $receipt,
            'statuses' => OperationsPresentation::receiptStatuses(),
            'canEdit' => $receipt->isEditable() && ($this->allowed('stock.update') || $this->allowed('stock.create')),
            'isNonFood' => $receipt->warehouse?->type === Warehouse::TYPE_NON_FOOD,
            'receiptTotalsByUnit' => $receipt->items
                ->groupBy(fn ($item): string => trim((string) $item->unit_snapshot) ?: 'unit')
                ->map(fn ($items, string $unit): array => [
                    'unit' => $unit,
                    'ordered' => (float) $items->sum('ordered_quantity'),
                    'accepted' => (float) $items->sum('accepted_quantity'),
                    'rejected' => (float) $items->sum('rejected_quantity'),
                ])->values(),
        ])->layout('layouts.v3', ['title' => 'Rincian Penerimaan']);
    }

    private function persist(): void
    {
        $receipt = $this->receipt()->load('items');
        abort_unless($receipt->isEditable() && ($this->allowed('stock.update') || $this->allowed('stock.create')), 403);
        $data = $this->validate([
            'receiptDate' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'itemDocumentations' => ['nullable', 'array'],
            'itemDocumentations.*' => ['nullable', 'array'],
            'itemDocumentations.*.*' => ['image', 'max:5120'],
            'rows' => ['required', 'array'],
            'rows.*.received_quantity' => ['required', 'numeric', 'min:0'],
            'rows.*.accepted_quantity' => ['required', 'numeric', 'min:0'],
            'rows.*.rejected_quantity' => ['required', 'numeric', 'min:0'],
            'rows.*.quality_notes' => ['nullable', 'string', 'max:1000'],
            'rows.*.supplier_batch_number' => ['nullable', 'string', 'max:100'],
            'rows.*.expired_date' => ['nullable', 'date'],
            'rows.*.received_temperature_celsius' => ['nullable', 'numeric', 'between:-50,100'],
        ]);

        foreach ($receipt->items as $item) {
            $row = $data['rows'][$item->id] ?? null;
            if (! is_array($row)) {
                continue;
            }
            app(StockReceiptService::class)->updateInspection($item, $row);

            foreach (data_get($data, 'itemDocumentations.'.$item->id, []) as $photo) {
                $path = $photo->store(
                    'stock-receipts/'.$receipt->receipt_date?->format('Y/m/d').'/items/'.$item->id,
                    'public',
                );
                $item->photos()->create([
                    'stock_receipt_id' => $receipt->getKey(),
                    'item_name_snapshot' => $item->ingredient_name_snapshot,
                    'photo_path' => $path,
                    'original_name' => $photo->getClientOriginalName(),
                    'uploaded_by' => auth()->id(),
                ]);
            }
        }

        $receipt->update([
            'receipt_date' => $data['receiptDate'],
            'received_by_name' => auth()->user()->name,
            'notes' => trim($data['notes']) ?: null,
        ]);
        $this->itemDocumentations = [];
    }

    private function receipt(): StockReceipt
    {
        $unit = $this->currentUnit();

        return StockReceipt::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($this->receiptId);
    }

    private function fillFromReceipt(StockReceipt $receipt): void
    {
        $receipt->load('items');
        $this->receiptDate = $receipt->receipt_date?->toDateString() ?? '';
        $this->notes = (string) $receipt->notes;
        $this->rows = $receipt->items->mapWithKeys(fn ($item): array => [$item->id => [
            'received_quantity' => (float) $item->received_quantity,
            'accepted_quantity' => (float) $item->accepted_quantity,
            'rejected_quantity' => (float) $item->rejected_quantity,
            'quality_notes' => (string) $item->quality_notes,
            'supplier_batch_number' => (string) $item->supplier_batch_number,
            'expired_date' => $item->expired_date?->toDateString(),
            'received_temperature_celsius' => $item->received_temperature_celsius,
        ]])->all();
    }

    private function runAction(callable $action): void
    {
        try {
            $this->actionMessage = $action();
            $this->fillFromReceipt($this->receipt());
            $this->resetErrorBag();
        } catch (Throwable $exception) {
            report($exception);
            $message = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->implode(' ')
                : $exception->getMessage();
            $this->addError('action', $message);
        }
    }
}
