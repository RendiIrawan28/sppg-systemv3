<?php

namespace App\Livewire\V3\Warehouse\Receipts;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\StockReceipt;
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

    public $documentation = null;

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

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('stock.view'), 403);
        $receipt = $this->receipt()->load(['procurementRequest', 'supplier', 'items.supplier']);

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
            'notes' => ['nullable', 'string', 'max:2000'],
            'documentation' => ['nullable', 'image', 'max:5120'],
            'rows' => ['required', 'array'],
            'rows.*.received_quantity' => ['required', 'numeric', 'min:0'],
            'rows.*.accepted_quantity' => ['required', 'numeric', 'min:0'],
            'rows.*.rejected_quantity' => ['required', 'numeric', 'min:0'],
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

            $received = (float) $row['received_quantity'];
            $accepted = (float) $row['accepted_quantity'];
            $rejected = (float) $row['rejected_quantity'];
            $ordered = (float) $item->ordered_quantity;
            $orderedKg = (float) $item->ordered_quantity_kg;
            $kgRatio = $ordered > 0 ? $orderedKg / $ordered : 0;
            $qualityStatus = match (true) {
                $accepted > 0 && $rejected > 0 => 'partial',
                $accepted > 0 => 'accepted',
                $rejected > 0 => 'rejected',
                default => 'pending',
            };

            $item->update([
                'received_quantity' => $received,
                'accepted_quantity' => $accepted,
                'rejected_quantity' => $rejected,
                'received_quantity_kg' => round($received * $kgRatio, 4),
                'accepted_quantity_kg' => round($accepted * $kgRatio, 4),
                'rejected_quantity_kg' => round($rejected * $kgRatio, 4),
                'quality_status' => $qualityStatus,
                'quality_notes' => trim((string) ($row['quality_notes'] ?? '')) ?: null,
            ]);
        }

        $oldDocumentationPath = $receipt->documentation_path;
        $newDocumentationPath = null;
        if ($this->documentation) {
            $newDocumentationPath = $this->documentation->store(
                'stock-receipts/'.$receipt->receipt_date?->format('Y/m/d'),
                'public',
            );
        }

        $receipt->update([
            'receipt_date' => $data['receiptDate'],
            'received_by_name' => auth()->user()->name,
            'notes' => trim($data['notes']) ?: null,
            'documentation_path' => $newDocumentationPath ?: $oldDocumentationPath,
        ]);

        if ($newDocumentationPath && $oldDocumentationPath && $oldDocumentationPath !== $newDocumentationPath) {
            Storage::disk('public')->delete($oldDocumentationPath);
        }

        $this->reset('documentation');
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
