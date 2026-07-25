<?php

namespace App\Livewire\V3\Procurement;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\Ingredient;
use App\Models\ProcurementRequest;
use App\Models\ProcurementRequestItem;
use App\Models\Supplier;
use App\Services\ProcurementRequestService;
use App\Services\StockReceiptService;
use App\Support\V3\OperationsPresentation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class Show extends Component
{
    use InteractsWithV3Shell;

    public int $requestId;

    public string $requestDate = '';

    public string $neededDate = '';

    public string $notes = '';

    public string $decisionNotes = '';

    public string $newIngredientId = '';

    public ?string $actionMessage = null;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public function mount(ProcurementRequest $procurement): void
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('procurement.view'), 403);
        abort_unless((int) $procurement->sppg_unit_id === (int) $unit->getKey(), 404);
        $this->requestId = $procurement->getKey();
        $this->fillFromRequest($procurement);
    }

    public function save(): void
    {
        $this->runAction(function (): string {
            $this->persist();

            return 'Perubahan permintaan pembelian berhasil disimpan.';
        });
    }

    public function addItem(ProcurementRequestService $service): void
    {
        abort_unless($this->allowed('procurement.update'), 403);
        $request = $this->request();
        abort_unless($request->isEditable(), 403);
        $unit = $this->currentUnit();

        $data = $this->validate([
            'newIngredientId' => [
                'required',
                'integer',
                Rule::exists('ingredients', 'id')
                    ->where('sppg_unit_id', $unit->getKey())
                    ->where('is_active', true),
            ],
        ], [
            'newIngredientId.required' => 'Pilih bahan yang akan ditambahkan.',
            'newIngredientId.exists' => 'Bahan tidak tersedia pada Unit SPPG aktif.',
        ]);

        $this->runAction(function () use ($data, $request, $service, $unit): string {
            $ingredient = Ingredient::query()
                ->with('measurementUnit')
                ->where('sppg_unit_id', $unit->getKey())
                ->where('is_active', true)
                ->findOrFail((int) $data['newIngredientId']);

            if ($request->items()->where('ingredient_id', $ingredient->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'newIngredientId' => 'Bahan tersebut sudah ada dalam daftar pembelian.',
                ]);
            }

            DB::transaction(function () use ($ingredient, $request, $service): void {
                $quantity = 1.0;
                $quantityKg = $this->quantityInKgForIngredient($ingredient, $quantity);

                $request->items()->create([
                    'nutrition_requirement_item_id' => null,
                    'ingredient_id' => $ingredient->getKey(),
                    'supplier_id' => null,
                    'ingredient_code_snapshot' => $ingredient->code,
                    'ingredient_name_snapshot' => $ingredient->name,
                    'unit_snapshot' => $ingredient->measurementUnit?->symbol
                        ?: $ingredient->measurementUnit?->code
                        ?: 'unit',
                    'requested_quantity' => $quantity,
                    'approved_quantity' => $quantity,
                    'requested_quantity_kg' => $quantityKg,
                    'approved_quantity_kg' => $quantityKg,
                    'estimated_unit_price' => (float) ($ingredient->reference_price ?? 0),
                    'estimated_total_price' => (float) ($ingredient->reference_price ?? 0),
                ]);

                $service->recalculate($request);
            });

            $this->newIngredientId = '';

            return "{$ingredient->name} berhasil ditambahkan ke item pembelian.";
        });
    }

    public function removeItem(int $itemId, ProcurementRequestService $service): void
    {
        abort_unless($this->allowed('procurement.update'), 403);
        $request = $this->request();
        abort_unless($request->isEditable(), 403);

        $item = $request->items()->findOrFail($itemId);

        $this->runAction(function () use ($item, $request, $service): string {
            $name = $item->ingredient_name_snapshot;

            DB::transaction(function () use ($item, $request, $service): void {
                $item->delete();
                $service->recalculate($request);
            });

            return "{$name} berhasil dihapus dari item pembelian.";
        });
    }

    public function submit(ProcurementRequestService $service): void
    {
        $this->runAction(function () use ($service): string {
            $this->persist();
            $service->submit($this->request());

            return 'Permintaan berhasil diajukan untuk pemilihan supplier dan harga.';
        });
    }

    public function verifyFinance(ProcurementRequestService $service): void
    {
        $this->runAction(function () use ($service): string {
            $this->persist();
            $service->verifyByFinance($this->request(), trim($this->decisionNotes) ?: null);
            $this->decisionNotes = '';

            return 'Harga berhasil diverifikasi Pengawas Keuangan.';
        });
    }

    public function finalizePrice(ProcurementRequestService $service): void
    {
        $this->runAction(function () use ($service): string {
            $service->finalizePriceByHead($this->request(), trim($this->decisionNotes) ?: null);
            $this->decisionNotes = '';

            return 'Harga final telah ditetapkan dan dikunci Kepala SPPG.';
        });
    }

    public function requestRevision(ProcurementRequestService $service): void
    {
        $this->validate(['decisionNotes' => ['required', 'string', 'min:5', 'max:2000']]);
        $this->runAction(function () use ($service): string {
            $service->requestRevision($this->request(), $this->decisionNotes);
            $this->decisionNotes = '';

            return 'Permintaan dikembalikan untuk revisi.';
        });
    }

    public function markOrdered(ProcurementRequestService $service): void
    {
        $this->runAction(function () use ($service): string {
            $service->markOrdered($this->request());

            return 'Pemesanan oleh Gudang berhasil dicatat.';
        });
    }

    public function createReceipt(StockReceiptService $service): void
    {
        abort_unless($this->allowed('stock.create'), 403);
        $this->runAction(function () use ($service): string {
            $receipts = $service->createGroupedFromProcurementRequest($this->request()->load('items'));

            if ($receipts->count() === 1) {
                $this->redirectRoute('v3.warehouse.receipts.show', ['receipt' => $receipts->first()], navigate: true);
            } else {
                session()->flash('v3.status', "{$receipts->count()} dokumen penerimaan dibuat berdasarkan supplier.");
                $this->redirectRoute('v3.warehouse.receipts.index', navigate: true);
            }

            return 'Dokumen penerimaan per supplier siap diisi.';
        });
    }

    public function delete(): void
    {
        abort_unless($this->allowed('procurement.delete'), 403);
        $request = $this->request();
        abort_unless($request->isEditable(), 403);
        $request->delete();
        session()->flash('v3.status', 'Draft permintaan pembelian berhasil dihapus.');
        $this->redirectRoute('v3.procurement.index', navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('procurement.view'), 403);
        $request = $this->request()->load(['nutritionRequirementPlan', 'items.supplier', 'creator', 'submitter', 'approver', 'priceFinalizer', 'orderer']);

        return view('livewire.v3.procurement.show', [
            ...$this->shellData($unit),
            'request' => $request,
            'statuses' => OperationsPresentation::procurementStatuses(),
            'suppliers' => Supplier::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->get(),
            'availableIngredients' => Ingredient::query()
                ->with('measurementUnit')
                ->where('sppg_unit_id', $unit->getKey())
                ->where('is_active', true)
                ->whereNotIn('id', $request->items->pluck('ingredient_id')->filter())
                ->orderBy('name')
                ->get(),
            'canHeaderEdit' => $this->allowed('procurement.update') && $request->isEditable(),
            'canSupplierEdit' => $this->allowed('procurement.select_supplier') && $request->status === ProcurementRequest::STATUS_SUBMITTED,
            'canPriceEdit' => $this->allowed('procurement.price_input') && $request->priceIsEditable(),
        ])->layout('layouts.v3', ['title' => 'Rincian Pengadaan']);
    }

    private function persist(): void
    {
        $request = $this->request()->load('items');
        $unit = $this->currentUnit();
        $canHeader = $this->allowed('procurement.update') && $request->isEditable();
        $canSupplier = $this->allowed('procurement.select_supplier') && $request->status === ProcurementRequest::STATUS_SUBMITTED;
        $canPrice = $this->allowed('procurement.price_input') && $request->priceIsEditable();
        abort_unless($canHeader || $canSupplier || $canPrice, 403);

        $data = $this->validate([
            'requestDate' => ['required', 'date'],
            'neededDate' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array'],
            'rows.*.requested_quantity' => ['required', 'numeric', 'gt:0'],
            'rows.*.supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('sppg_unit_id', $unit->getKey())],
            'rows.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'rows.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($canHeader) {
            $request->update([
                'request_date' => $data['requestDate'],
                'needed_date' => $data['neededDate'] ?: null,
                'notes' => trim($data['notes']) ?: null,
            ]);
        }

        foreach ($request->items as $item) {
            $row = $data['rows'][$item->id] ?? null;
            if (! is_array($row)) {
                continue;
            }

            $updates = [];
            if ($canHeader) {
                $quantity = (float) $row['requested_quantity'];
                $quantityKg = $this->quantityInKgForItem($item, $quantity);
                $updates['requested_quantity'] = $quantity;
                $updates['approved_quantity'] = $quantity;
                $updates['requested_quantity_kg'] = $quantityKg;
                $updates['approved_quantity_kg'] = $quantityKg;
                $updates['notes'] = trim((string) ($row['notes'] ?? '')) ?: null;
            }
            if ($canSupplier) {
                $updates['supplier_id'] = $row['supplier_id'] ?: null;
            }
            if ($canPrice) {
                $updates['estimated_unit_price'] = $row['estimated_unit_price'] ?: 0;
            }
            if ($updates !== []) {
                $item->update($updates);
            }
        }

        $service = app(ProcurementRequestService::class);
        $canPrice ? $service->savePrices($request) : $service->recalculate($request);
    }

    private function request(): ProcurementRequest
    {
        $unit = $this->currentUnit();

        return ProcurementRequest::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($this->requestId);
    }

    private function fillFromRequest(ProcurementRequest $request): void
    {
        $request->load('items');
        $this->requestDate = $request->request_date?->toDateString() ?? '';
        $this->neededDate = $request->needed_date?->toDateString() ?? '';
        $this->notes = (string) $request->notes;
        $this->rows = $request->items->mapWithKeys(fn ($item): array => [
            $item->id => [
                'requested_quantity' => (float) $item->requested_quantity,
                'supplier_id' => $item->supplier_id,
                'estimated_unit_price' => (float) $item->estimated_unit_price,
                'notes' => (string) $item->notes,
            ],
        ])->all();
    }

    private function quantityInKgForIngredient(Ingredient $ingredient, float $quantity): float
    {
        $gramsPerUnit = (float) ($ingredient->grams_per_unit
            ?: $ingredient->measurementUnit?->to_base_factor
            ?: 0);

        return round($quantity * $gramsPerUnit / 1000, 4);
    }

    private function quantityInKgForItem(ProcurementRequestItem $item, float $quantity): float
    {
        $currentQuantity = (float) $item->requested_quantity;
        $currentQuantityKg = (float) $item->requested_quantity_kg;

        if ($currentQuantity > 0 && $currentQuantityKg > 0) {
            return round($quantity * ($currentQuantityKg / $currentQuantity), 4);
        }

        $item->loadMissing('ingredient.measurementUnit');

        if ($item->ingredient) {
            return $this->quantityInKgForIngredient($item->ingredient, $quantity);
        }

        return match (strtolower((string) $item->unit_snapshot)) {
            'kg' => round($quantity, 4),
            'g', 'gram' => round($quantity / 1000, 4),
            default => 0.0,
        };
    }

    private function runAction(callable $action): void
    {
        try {
            $this->actionMessage = $action();
            $this->fillFromRequest($this->request());
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
