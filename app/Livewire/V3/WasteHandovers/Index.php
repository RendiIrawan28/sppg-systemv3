<?php

namespace App\Livewire\V3\WasteHandovers;

use App\Enums\WasteDivision;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\WasteHandoverReport;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
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

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedDivision(): void { $this->resetPage(); }

    public function render()
    {
        $unit = $this->currentUnit();
        $allowed = $this->allowedDivisions('view');
        $records = WasteHandoverReport::query()
            ->with(['items', 'petugas'])
            ->where('sppg_unit_id', $unit->getKey())
            ->whereIn('division_type', array_keys($allowed))
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
        ])->layout('layouts.v3', ['title' => 'Berita Acara Limbah']);
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
