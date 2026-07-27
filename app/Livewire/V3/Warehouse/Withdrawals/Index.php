<?php

namespace App\Livewire\V3\Warehouse\Withdrawals;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\FieldDistributionPlan;
use App\Models\InventoryLot;
use App\Models\PortioningSession;
use App\Models\ProcessingBatch;
use App\Models\WarehouseWithdrawal;
use App\Services\FieldOperationalPlanGenerator;
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

    public string $referenceId = '';

    public string $notes = '';

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
            'referenceId' => ['required', 'string', 'regex:/^(record|plan):[1-9][0-9]*$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array', 'min:1'], 'rows.*.inventory_lot_id' => ['required', 'integer'],
            'rows.*.quantity' => ['required', 'numeric', 'gt:0'],
            'rows.*.pickup_temperature_celsius' => ['nullable', 'numeric', 'between:-40,40'],
            'rows.*.photo' => ['required', 'image', 'max:5120'],
        ]);
        $division = $this->divisionCode();
        $referenceId = $this->resolveManualReference($data['referenceId']);
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
                referenceId: $referenceId,
                purposeReference: '',
                notes: filled($data['notes']) ? trim($data['notes']) : null,
                rows: $data['rows'],
                actor: auth()->user(),
            );
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }
        $this->reset('referenceId', 'notes');
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
            'references' => $this->references(),
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

    /** @return array<int, array{value: string, label: string}> */
    private function references(): array
    {
        $unitId = $this->currentUnit()->id;

        return match ($this->divisionCode()) {
            'persiapan' => FieldDistributionPlan::query()
                ->where('sppg_unit_id', $unitId)
                ->where('status', 'activated')
                ->orderByDesc('production_date')
                ->orderByDesc('id')
                ->get()
                ->map(fn (FieldDistributionPlan $plan): array => [
                    'value' => "record:{$plan->id}",
                    'label' => "{$plan->plan_number} · {$plan->menu_name_snapshot} · {$plan->production_date?->format('d-m-Y')}",
                ])->all(),
            'pengolahan' => $this->processingReferences($unitId),
            'pemorsian' => $this->portioningReferences($unitId),
            default => [],
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    private function processingReferences(int $unitId): array
    {
        $batches = ProcessingBatch::query()
            ->where('sppg_unit_id', $unitId)
            ->whereIn('state', ['planned', 'in_progress'])
            ->orderByRaw("CASE WHEN state = 'in_progress' THEN 0 ELSE 1 END")
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->get();
        $references = $batches->map(fn (ProcessingBatch $batch): array => [
            'value' => "record:{$batch->id}",
            'label' => "{$batch->batch_number} · {$batch->product_name} · {$batch->production_date?->format('d-m-Y')}",
        ]);
        $batchPlanIds = $batches->pluck('field_distribution_plan_id')->filter()->map(fn ($id): int => (int) $id);
        $plans = FieldDistributionPlan::query()
            ->where('sppg_unit_id', $unitId)
            ->where('status', 'activated')
            ->when($batchPlanIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $batchPlanIds))
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (FieldDistributionPlan $plan): array => [
                'value' => "plan:{$plan->id}",
                'label' => "{$plan->plan_number} · {$plan->menu_name_snapshot} · {$plan->production_date?->format('d-m-Y')} (buat batch Pengolahan)",
            ]);

        return $references->concat($plans)->values()->all();
    }

    /** @return array<int, array{value: string, label: string}> */
    private function portioningReferences(int $unitId): array
    {
        $sessions = PortioningSession::query()
            ->where('sppg_unit_id', $unitId)
            ->whereIn('state', ['planned', 'in_progress'])
            ->orderByRaw("CASE WHEN state = 'in_progress' THEN 0 ELSE 1 END")
            ->orderByDesc('portioning_date')
            ->orderByDesc('id')
            ->get();
        $references = $sessions->map(fn (PortioningSession $session): array => [
            'value' => "record:{$session->id}",
            'label' => "{$session->session_number} · {$session->menu_name_snapshot} · {$session->portioning_date?->format('d-m-Y')}",
        ]);
        $linkedPlanIds = PortioningSession::query()
            ->where('sppg_unit_id', $unitId)
            ->whereNotNull('field_distribution_plan_id')
            ->pluck('field_distribution_plan_id');
        $plans = FieldDistributionPlan::query()
            ->where('sppg_unit_id', $unitId)
            ->where('status', 'activated')
            ->when($linkedPlanIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $linkedPlanIds))
            ->orderByDesc('production_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (FieldDistributionPlan $plan): array => [
                'value' => "plan:{$plan->id}",
                'label' => "{$plan->plan_number} · {$plan->menu_name_snapshot} · {$plan->distribution_date?->format('d-m-Y')} (buat sesi Pemorsian)",
            ]);

        return $references->concat($plans)->values()->all();
    }

    private function resolveManualReference(string $selection): int
    {
        [$type, $id] = explode(':', $selection, 2);
        if ($type === 'record') {
            return (int) $id;
        }
        $division = $this->divisionCode();
        if ($type !== 'plan' || ! in_array($division, ['pengolahan', 'pemorsian'], true)) {
            throw ValidationException::withMessages([
                'referenceId' => 'Rencana/batch yang dipilih tidak sesuai dengan divisi.',
            ]);
        }
        $plan = FieldDistributionPlan::query()
            ->where('sppg_unit_id', $this->currentUnit()->id)
            ->where('status', 'activated')
            ->find($id);
        if (! $plan) {
            throw ValidationException::withMessages([
                'referenceId' => 'Rencana produksi tidak aktif atau tidak ditemukan.',
            ]);
        }

        $generator = app(FieldOperationalPlanGenerator::class);

        return match ($division) {
            'pengolahan' => $generator->generateProcessingBatch($plan, auth()->user())->getKey(),
            'pemorsian' => $generator->generatePortioningSession($plan, auth()->user())->getKey(),
        };
    }
}
