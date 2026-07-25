<?php

namespace App\Livewire\V3\Warehouse\Withdrawals;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\FieldDistributionPlan;
use App\Models\InventoryLot;
use App\Models\PortioningSession;
use App\Models\ProcessingBatch;
use App\Models\WarehouseWithdrawal;
use App\Services\WarehouseWithdrawalService;
use App\Support\DivisionRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    use InteractsWithV3Shell, WithFileUploads, WithPagination;

    public array $rows = [];

    public array $actualQuantities = [];

    public string $decisionNotes = '';

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->canTake() || $this->allowed('stock.view'), 403);
        $this->addRow();
    }

    public function addRow(): void
    {
        $this->rows[] = ['inventory_lot_id' => '', 'quantity' => '', 'pickup_temperature_celsius' => '', 'photo' => null];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function submit(WarehouseWithdrawalService $service): void
    {
        abort_unless($this->canTake(), 403);
        $unit = $this->currentUnit();
        $data = $this->validate([
            'rows' => ['required', 'array', 'min:1'], 'rows.*.inventory_lot_id' => ['required', 'integer'],
            'rows.*.quantity' => ['required', 'numeric', 'gt:0'],
            'rows.*.pickup_temperature_celsius' => ['nullable', 'numeric', 'between:-40,40'],
            'rows.*.photo' => ['required', 'image', 'max:5120'],
        ]);
        $division = $this->divisionCode();
        $reference = $this->activeReference();
        if (! $reference) {
            throw ValidationException::withMessages([
                'rows' => 'Belum ada pekerjaan aktif untuk Divisi '.ucfirst((string) $division).'.',
            ]);
        }
        $storedPaths = [];
        try {
            foreach ($data['rows'] as $index => $row) {
                $path = $row['photo']->store('warehouse-withdrawals/'.today()->format('Y/m/d'), 'public');
                $storedPaths[] = $path;
                $data['rows'][$index]['photo_path'] = $path;
                unset($data['rows'][$index]['photo']);
            }
            $service->createAndSubmit(
                unitId: $unit->id,
                divisionCode: (string) $division,
                referenceType: $this->referenceType(),
                referenceId: (int) $reference->getKey(),
                purposeReference: '',
                notes: null,
                rows: $data['rows'],
                actor: auth()->user(),
            );
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }
        $this->rows = [];
        $this->addRow();
        session()->flash('v3.status', 'Pengambilan tercatat dan bahan langsung tersedia di halaman divisi. Gudang tetap perlu memverifikasi stok.');
    }

    public function verify(int $id, WarehouseWithdrawalService $service): void
    {
        abort_unless($this->allowed('stock.update') || $this->allowed('stock.create'), 403);
        $record = $this->record($id)->load('items');
        $rules = [];
        foreach ($record->items as $item) {
            $rules["actualQuantities.{$id}.{$item->id}"] = ['required', 'numeric', 'gt:0'];
        }
        $this->validate($rules);
        $service->verify($record, auth()->user(), $this->actualQuantities[$id] ?? []);
        unset($this->actualQuantities[$id]);
        session()->flash('v3.status', 'Jenis dan jumlah barang terverifikasi. Stok lot telah dikurangi.');
    }

    public function revise(int $id, WarehouseWithdrawalService $service): void
    {
        abort_unless($this->allowed('stock.update') || $this->allowed('stock.create'), 403);
        $this->validate(['decisionNotes' => ['required', 'string', 'max:2000']]);
        $service->requestRevision($this->record($id), auth()->user(), $this->decisionNotes);
        $this->decisionNotes = '';
    }

    public function reject(int $id, WarehouseWithdrawalService $service): void
    {
        abort_unless($this->allowed('stock.update') || $this->allowed('stock.create'), 403);
        $this->validate(['decisionNotes' => ['required', 'string', 'max:2000']]);
        $service->reject($this->record($id), auth()->user(), $this->decisionNotes);
        $this->decisionNotes = '';
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $lots = InventoryLot::with('ingredient')->where('sppg_unit_id', $unit->id)->where('status', InventoryLot::AVAILABLE)
            ->where('balance_quantity', '>', 0)->where(fn ($q) => $q->whereNull('expired_date')->orWhereDate('expired_date', '>=', today()))
            ->orderByRaw('expired_date IS NULL')->orderBy('expired_date')->get();
        $reserved = DB::table('warehouse_withdrawal_items')
            ->join('warehouse_withdrawals', 'warehouse_withdrawals.id', '=', 'warehouse_withdrawal_items.warehouse_withdrawal_id')
            ->where('warehouse_withdrawals.sppg_unit_id', $unit->id)
            ->where('warehouse_withdrawals.status', WarehouseWithdrawal::WAITING)
            ->select('warehouse_withdrawal_items.inventory_lot_id', DB::raw('SUM(warehouse_withdrawal_items.requested_quantity) reserved_quantity'))
            ->groupBy('warehouse_withdrawal_items.inventory_lot_id')
            ->pluck('reserved_quantity', 'inventory_lot_id');
        foreach ($lots as $lot) {
            $lot->setAttribute('reserved_quantity', (float) ($reserved[$lot->id] ?? 0));
            $lot->setAttribute('available_quantity', max(0, (float) $lot->balance_quantity - (float) $lot->reserved_quantity));
        }
        $lots = $lots->filter(fn (InventoryLot $lot): bool => (float) $lot->available_quantity > 0.0001)->values();
        $records = WarehouseWithdrawal::with(['items', 'taker', 'verifier'])->where('sppg_unit_id', $unit->id)
            ->when(! $this->allowed('stock.view'), fn ($q) => $q->where('taken_by', auth()->id()))
            ->latest('id')->paginate(15);
        foreach ($records as $record) {
            if ($record->status !== WarehouseWithdrawal::WAITING) {
                continue;
            }
            foreach ($record->items as $item) {
                $this->actualQuantities[$record->id][$item->id] ??= (string) ($item->actual_quantity ?? $item->requested_quantity ?? $item->taken_quantity_kg);
            }
        }

        return view('livewire.v3.warehouse.withdrawals.index', [
            ...$this->shellData($unit), 'lots' => $lots, 'records' => $records, 'canTake' => $this->canTake(),
            'canVerify' => $this->allowed('stock.update') || $this->allowed('stock.create'),
            'pendingToday' => WarehouseWithdrawal::query()->where('sppg_unit_id', $unit->id)
                ->where('status', WarehouseWithdrawal::WAITING)->count(),
            'pendingOverdue' => WarehouseWithdrawal::query()->where('sppg_unit_id', $unit->id)
                ->whereDate('withdrawal_date', '<', today())->where('status', WarehouseWithdrawal::WAITING)->count(),
        ])->layout('layouts.v3', ['title' => 'Pengambilan Gudang']);
    }

    private function record(int $id): WarehouseWithdrawal
    {
        return WarehouseWithdrawal::where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
    }

    private function divisionCode(): ?string
    {
        foreach (auth()->user()->roles as $role) {
            if ($code = DivisionRole::divisionCodeForRole($role->name)) {
                return $code;
            }
        }

        return null;
    }

    private function canTake(): bool
    {
        return in_array($this->divisionCode(), ['persiapan', 'pengolahan', 'pemorsian'], true);
    }

    private function referenceType(): string
    {
        return match ($this->divisionCode()) {
            'persiapan' => 'field_plan',
            'pengolahan' => 'processing_batch',
            'pemorsian' => 'portioning_session',
            default => '',
        };
    }

    private function activeReference(): FieldDistributionPlan|ProcessingBatch|PortioningSession|null
    {
        $unitId = $this->currentUnit()->id;
        $today = today()->toDateString();

        return match ($this->divisionCode()) {
            'persiapan' => FieldDistributionPlan::query()->where('sppg_unit_id', $unitId)->where('status', 'activated')
                ->orderByRaw('CASE WHEN production_date = ? THEN 0 ELSE 1 END', [$today])
                ->orderByDesc('production_date')->orderByDesc('id')->first(),
            'pengolahan' => ProcessingBatch::query()->where('sppg_unit_id', $unitId)->whereIn('state', ['planned', 'in_progress'])
                ->orderByRaw("CASE WHEN state = 'in_progress' THEN 0 ELSE 1 END")
                ->orderByRaw('CASE WHEN production_date = ? THEN 0 ELSE 1 END', [$today])
                ->orderByDesc('production_date')->orderByDesc('id')->first(),
            'pemorsian' => PortioningSession::query()->where('sppg_unit_id', $unitId)->whereIn('state', ['planned', 'in_progress'])
                ->orderByRaw("CASE WHEN state = 'in_progress' THEN 0 ELSE 1 END")
                ->orderByRaw('CASE WHEN portioning_date = ? THEN 0 ELSE 1 END', [$today])
                ->orderByDesc('portioning_date')->orderByDesc('id')->first(),
            default => null,
        };
    }
}
