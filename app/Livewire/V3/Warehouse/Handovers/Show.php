<?php

namespace App\Livewire\V3\Warehouse\Handovers;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\PreparationMaterialHandover;
use App\Services\PreparationMaterialHandoverService;
use App\Support\V3\OperationsPresentation;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class Show extends Component
{
    use InteractsWithV3Shell;

    public int $handoverId;

    public string $handoverDate = '';

    public string $warehouseOfficerName = '';

    public string $preparationOfficerName = '';

    public string $notes = '';

    public ?string $actionMessage = null;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function mount(PreparationMaterialHandover $handover): void
    {
        $unit = $this->currentUnit();
        $this->authorizeView();
        abort_unless((int) $handover->sppg_unit_id === (int) $unit->getKey(), 404);
        $this->handoverId = $handover->getKey();
        $this->fillFromHandover($handover);
    }

    public function save(): void
    {
        $this->runAction(function (): string {
            $this->persist();

            return 'Perubahan serah bahan berhasil disimpan.';
        });
    }

    public function markHandedOver(PreparationMaterialHandoverService $service): void
    {
        abort_unless($this->allowed('stock.create'), 403);
        $this->runAction(function () use ($service): string {
            $this->persist();
            $service->markHandedOver($this->handover());

            return 'Bahan diserahkan ke Persiapan dan stok telah berkurang.';
        });
    }

    public function markReceived(PreparationMaterialHandoverService $service): void
    {
        abort_unless($this->allowed('preparation.update'), 403);
        $this->runAction(function () use ($service): string {
            $service->markReceived($this->handover());

            return 'Bahan diterima oleh tim Persiapan.';
        });
    }

    public function markInspected(PreparationMaterialHandoverService $service): void
    {
        abort_unless($this->allowed('preparation.update'), 403);
        $this->runAction(function () use ($service): string {
            $this->persist();
            $service->markInspected($this->handover());

            return 'Pemeriksaan kondisi bahan berhasil disimpan.';
        });
    }

    public function markPrepared(PreparationMaterialHandoverService $service): void
    {
        abort_unless($this->allowed('preparation.update'), 403);
        $this->runAction(function () use ($service): string {
            $this->persist();
            $service->markPrepared($this->handover());

            return 'Bahan ditandai siap olah.';
        });
    }

    public function markWasteRecorded(PreparationMaterialHandoverService $service): void
    {
        abort_unless($this->allowed('preparation.update'), 403);
        $this->runAction(function () use ($service): string {
            $this->persist();
            $service->markWasteRecorded($this->handover());

            return 'Catatan limbah persiapan berhasil disimpan.';
        });
    }

    public function delete(): void
    {
        abort_unless($this->allowed('stock.update') || $this->allowed('stock.create'), 403);
        $handover = $this->handover();
        abort_unless($handover->isEditable(), 403);
        $handover->delete();
        session()->flash('v3.status', 'Draft serah bahan berhasil dihapus.');
        $this->redirectRoute('v3.warehouse.handovers.index', navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $this->authorizeView();
        $handover = $this->handover()->load(['items', 'fieldDistributionPlan', 'processingBatch']);

        return view('livewire.v3.warehouse.handovers.show', [
            ...$this->shellData($unit),
            'handover' => $handover,
            'statuses' => OperationsPresentation::handoverStatuses(),
            'canWarehouseEdit' => $handover->isEditable() && ($this->allowed('stock.update') || $this->allowed('stock.create')),
            'canPreparationEdit' => $handover->isPreparationEditable() && $this->allowed('preparation.update'),
        ])->layout('layouts.v3', ['title' => 'Rincian Serah Bahan']);
    }

    private function persist(): void
    {
        $handover = $this->handover()->load('items');
        $canWarehouse = $handover->isEditable() && ($this->allowed('stock.update') || $this->allowed('stock.create'));
        $canPreparation = $handover->isPreparationEditable() && $this->allowed('preparation.update');
        abort_unless($canWarehouse || $canPreparation, 403);
        $data = $this->validate([
            'handoverDate' => ['required', 'date'],
            'warehouseOfficerName' => ['nullable', 'string', 'max:255'],
            'preparationOfficerName' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array'],
            'rows.*.handed_over_quantity' => ['required', 'numeric', 'min:0'],
            'rows.*.handed_over_quantity_kg' => ['required', 'numeric', 'min:0'],
            'rows.*.supplier_batch_number' => ['nullable', 'string', 'max:255'],
            'rows.*.expired_date' => ['nullable', 'date'],
            'rows.*.received_quantity' => ['nullable', 'numeric', 'min:0'],
            'rows.*.good_quantity' => ['nullable', 'numeric', 'min:0'],
            'rows.*.moderate_quantity' => ['nullable', 'numeric', 'min:0'],
            'rows.*.damaged_quantity' => ['nullable', 'numeric', 'min:0'],
            'rows.*.inspection_notes' => ['nullable', 'string', 'max:1000'],
            'rows.*.prepared_quantity' => ['nullable', 'numeric', 'min:0'],
            'rows.*.preparation_notes' => ['nullable', 'string', 'max:1000'],
            'rows.*.waste_type' => ['nullable', 'string', 'max:255'],
            'rows.*.waste_quantity' => ['nullable', 'numeric', 'min:0'],
            'rows.*.waste_unit_snapshot' => ['nullable', 'string', 'max:80'],
            'rows.*.waste_notes' => ['nullable', 'string', 'max:1000'],
            'rows.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $header = ['notes' => trim($data['notes']) ?: null];
        if ($canWarehouse) {
            $header += [
                'handover_date' => $data['handoverDate'],
                'warehouse_officer_name' => trim($data['warehouseOfficerName']) ?: null,
            ];
        }
        if ($canPreparation) {
            $header['preparation_officer_name'] = trim($data['preparationOfficerName']) ?: null;
        }
        $handover->update($header);

        foreach ($handover->items as $item) {
            $row = $data['rows'][$item->id] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $updates = ['notes' => trim((string) ($row['notes'] ?? '')) ?: null];
            if ($canWarehouse) {
                $updates += [
                    'handed_over_quantity' => $row['handed_over_quantity'],
                    'handed_over_quantity_kg' => $row['handed_over_quantity_kg'],
                    'supplier_batch_number' => trim((string) ($row['supplier_batch_number'] ?? '')) ?: null,
                    'expired_date' => $row['expired_date'] ?: null,
                ];
            }
            if ($canPreparation) {
                foreach (['received_quantity', 'good_quantity', 'moderate_quantity', 'damaged_quantity', 'prepared_quantity', 'waste_quantity'] as $field) {
                    $updates[$field] = $row[$field] === '' ? null : $row[$field];
                }
                foreach (['inspection_notes', 'preparation_notes', 'waste_type', 'waste_unit_snapshot', 'waste_notes'] as $field) {
                    $updates[$field] = trim((string) ($row[$field] ?? '')) ?: null;
                }
            }
            $item->update($updates);
        }
    }

    private function handover(): PreparationMaterialHandover
    {
        $unit = $this->currentUnit();

        return PreparationMaterialHandover::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($this->handoverId);
    }

    private function fillFromHandover(PreparationMaterialHandover $handover): void
    {
        $handover->load('items');
        $this->handoverDate = $handover->handover_date?->toDateString() ?? '';
        $this->warehouseOfficerName = (string) $handover->warehouse_officer_name;
        $this->preparationOfficerName = (string) $handover->preparation_officer_name;
        $this->notes = (string) $handover->notes;
        $this->rows = $handover->items->mapWithKeys(fn ($item): array => [$item->id => [
            'handed_over_quantity' => (float) $item->handed_over_quantity,
            'handed_over_quantity_kg' => (float) $item->handed_over_quantity_kg,
            'supplier_batch_number' => (string) $item->supplier_batch_number,
            'expired_date' => $item->expired_date?->toDateString() ?? '',
            'received_quantity' => $item->received_quantity !== null ? (float) $item->received_quantity : '',
            'good_quantity' => $item->good_quantity !== null ? (float) $item->good_quantity : '',
            'moderate_quantity' => $item->moderate_quantity !== null ? (float) $item->moderate_quantity : '',
            'damaged_quantity' => $item->damaged_quantity !== null ? (float) $item->damaged_quantity : '',
            'inspection_notes' => (string) $item->inspection_notes,
            'prepared_quantity' => $item->prepared_quantity !== null ? (float) $item->prepared_quantity : '',
            'preparation_notes' => (string) $item->preparation_notes,
            'waste_type' => (string) $item->waste_type,
            'waste_quantity' => $item->waste_quantity !== null ? (float) $item->waste_quantity : '',
            'waste_unit_snapshot' => (string) ($item->waste_unit_snapshot ?: $item->unit_snapshot),
            'waste_notes' => (string) $item->waste_notes,
            'notes' => (string) $item->notes,
        ]])->all();
    }

    private function authorizeView(): void
    {
        abort_unless($this->allowed('stock.view') || $this->allowed('preparation.view'), 403);
    }

    private function runAction(callable $action): void
    {
        try {
            $this->actionMessage = $action();
            $this->fillFromHandover($this->handover());
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
