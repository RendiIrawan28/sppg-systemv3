<?php

namespace App\Livewire\V3\Warehouse\Receipts;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\StockReceipt;
use App\Services\PreparationMaterialHandoverService;
use App\Services\StockReceiptService;
use App\Support\V3\OperationsPresentation;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class Show extends Component
{
    use InteractsWithV3Shell;

    public int $receiptId;

    public string $receiptDate = '';

    public string $receivedByName = '';

    public string $notes = '';

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

    public function createHandover(PreparationMaterialHandoverService $service): void
    {
        abort_unless($this->allowed('stock.create'), 403);
        $this->runAction(function () use ($service): string {
            $handover = $service->createFromStockReceipt($this->receipt()->load('items'));
            $this->redirectRoute('v3.warehouse.handovers.show', ['handover' => $handover], navigate: true);

            return 'Dokumen serah bahan ke Persiapan siap diisi.';
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

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
        $receipt = $this->receipt()->load(['procurementRequest', 'items.supplier']);

        return view('livewire.v3.warehouse.receipts.show', [
            ...$this->shellData($unit),
            'receipt' => $receipt,
            'statuses' => OperationsPresentation::receiptStatuses(),
            'canEdit' => $receipt->isEditable() && ($this->allowed('stock.update') || $this->allowed('stock.create')),
        ])->layout('layouts.v3', ['title' => 'Rincian Penerimaan']);
    }

    private function persist(): void
    {
        $receipt = $this->receipt()->load('items');
        abort_unless($receipt->isEditable() && ($this->allowed('stock.update') || $this->allowed('stock.create')), 403);
        $data = $this->validate([
            'receiptDate' => ['required', 'date'],
            'receivedByName' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array'],
            'rows.*.received_quantity' => ['required', 'numeric', 'min:0'],
            'rows.*.accepted_quantity' => ['required', 'numeric', 'min:0'],
            'rows.*.rejected_quantity' => ['required', 'numeric', 'min:0'],
            'rows.*.received_quantity_kg' => ['required', 'numeric', 'min:0'],
            'rows.*.accepted_quantity_kg' => ['required', 'numeric', 'min:0'],
            'rows.*.rejected_quantity_kg' => ['required', 'numeric', 'min:0'],
            'rows.*.supplier_batch_number' => ['nullable', 'string', 'max:255'],
            'rows.*.expired_date' => ['nullable', 'date'],
            'rows.*.received_temperature_celsius' => ['nullable', 'numeric', 'between:-50,100'],
            'rows.*.quality_status' => ['required', Rule::in(['pending', 'accepted', 'partial', 'rejected'])],
            'rows.*.quality_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($receipt->items as $item) {
            $row = $data['rows'][$item->id] ?? null;
            if (! is_array($row)) {
                continue;
            }
            if ((float) $row['accepted_quantity'] + (float) $row['rejected_quantity'] > (float) $row['received_quantity'] + 0.0001) {
                throw ValidationException::withMessages(['rows' => "Jumlah baik + ditolak untuk {$item->ingredient_name_snapshot} melebihi jumlah diterima."]);
            }
            if ((float) $row['accepted_quantity_kg'] + (float) $row['rejected_quantity_kg'] > (float) $row['received_quantity_kg'] + 0.0001) {
                throw ValidationException::withMessages(['rows' => "Berat baik + ditolak untuk {$item->ingredient_name_snapshot} melebihi berat diterima."]);
            }
            $item->update([
                ...$row,
                'supplier_batch_number' => trim((string) ($row['supplier_batch_number'] ?? '')) ?: null,
                'expired_date' => $row['expired_date'] ?: null,
                'received_temperature_celsius' => $row['received_temperature_celsius'] === '' ? null : $row['received_temperature_celsius'],
                'quality_notes' => trim((string) ($row['quality_notes'] ?? '')) ?: null,
            ]);
        }

        $receipt->update([
            'receipt_date' => $data['receiptDate'],
            'received_by_name' => trim($data['receivedByName']) ?: null,
            'notes' => trim($data['notes']) ?: null,
        ]);
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
        $this->receivedByName = (string) $receipt->received_by_name;
        $this->notes = (string) $receipt->notes;
        $this->rows = $receipt->items->mapWithKeys(fn ($item): array => [$item->id => [
            'received_quantity' => (float) $item->received_quantity,
            'accepted_quantity' => (float) $item->accepted_quantity,
            'rejected_quantity' => (float) $item->rejected_quantity,
            'received_quantity_kg' => (float) $item->received_quantity_kg,
            'accepted_quantity_kg' => (float) $item->accepted_quantity_kg,
            'rejected_quantity_kg' => (float) $item->rejected_quantity_kg,
            'supplier_batch_number' => (string) $item->supplier_batch_number,
            'expired_date' => $item->expired_date?->toDateString() ?? '',
            'received_temperature_celsius' => $item->received_temperature_celsius !== null ? (float) $item->received_temperature_celsius : '',
            'quality_status' => $item->quality_status ?: 'pending',
            'quality_notes' => (string) $item->quality_notes,
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
