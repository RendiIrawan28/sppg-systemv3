<?php

namespace App\Livewire\V3\WasteHandovers;

use App\Enums\WasteDivision;
use App\Livewire\V3\Concerns\FiltersByWorkDate;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\WasteHandoverReport;
use App\Services\BulkOperationalReportReviewService;
use App\Services\WasteHandoverWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use FiltersByWorkDate;
    use InteractsWithV3Shell;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'divisi')]
    public string $division = '';

    public function mount(): void
    {
        abort_unless($this->canViewAny(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDivision(): void
    {
        $this->resetPage();
    }

    public function approveAll(BulkOperationalReportReviewService $bulk, WasteHandoverWorkflow $workflow): void
    {
        $divisions = $this->allowedDivisions('approve');
        abort_unless($divisions !== [], 403);
        $permissions = collect(array_keys($divisions))
            ->map(fn (string $code) => WasteDivision::from($code)->permissionPrefix().'.approve')->all();
        $count = $bulk->review(
            $this->reviewScope($this->currentUnit()->getKey(), array_keys($divisions)),
            auth()->user(), $permissions,
            fn (WasteHandoverReport $report, $actor) => $workflow->verify($report, $actor),
        );
        session()->flash('v3.status', "{$count} berita acara limbah berhasil disetujui pada tahap ini.");
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $allowed = $this->allowedDivisions('view');
        $records = WasteHandoverReport::query()
            ->with(['items', 'petugas'])
            ->where('sppg_unit_id', $unit->getKey())
            ->whereIn('division_type', array_keys($allowed))
            ->whereDate('report_date', $this->selectedWorkDate())
            ->when($this->division !== '', fn ($query) => $query->where('division_type', $this->division))
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('report_number', 'like', '%'.$this->search.'%')
                        ->orWhere('first_party_name', 'like', '%'.$this->search.'%')
                        ->orWhere('second_party_name', 'like', '%'.$this->search.'%');
                });
            })
            ->latest('report_date')
            ->latest('id')
            ->paginate(15);

        return view('livewire.v3.waste-handovers.index', [
            ...$this->shellData($unit),
            'records' => $records,
            'divisionOptions' => $allowed,
            'canCreate' => $this->allowedDivisions('update') !== [],
            'bulkReviewCount' => app(BulkOperationalReportReviewService::class)->pendingCount(
                $this->reviewScope($unit->getKey(), array_keys($this->allowedDivisions('approve'))),
                auth()->user(), collect(array_keys($this->allowedDivisions('approve')))
                    ->map(fn (string $code) => WasteDivision::from($code)->permissionPrefix().'.approve')->all(),
            ),
        ])->layout('layouts.v3', ['title' => 'Berita Acara Limbah']);
    }

    /** @param array<int, string> $divisions */
    private function reviewScope(int $unitId, array $divisions): Builder
    {
        return WasteHandoverReport::query()->where('sppg_unit_id', $unitId)
            ->whereIn('division_type', $divisions)
            ->whereDate('report_date', $this->selectedWorkDate())
            ->where(fn (Builder $query) => $query->whereNull('source_type')->orWhere('source_type', '!=', 'preparation_session'))
            ->when($this->division !== '', fn (Builder $query) => $query->where('division_type', $this->division))
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $search) => $search
                ->where('report_number', 'like', '%'.$this->search.'%')
                ->orWhere('first_party_name', 'like', '%'.$this->search.'%')
                ->orWhere('second_party_name', 'like', '%'.$this->search.'%')));
    }

    /** @return array<string, string> */
    private function allowedDivisions(string $action): array
    {
        return collect(WasteDivision::cases())
            ->filter(fn (WasteDivision $division): bool => $this->allowed($division->permissionPrefix().'.'.$action))
            ->mapWithKeys(fn (WasteDivision $division): array => [$division->value => $division->label()])
            ->all();
    }

    private function canViewAny(): bool
    {
        return $this->allowedDivisions('view') !== [];
    }
}
