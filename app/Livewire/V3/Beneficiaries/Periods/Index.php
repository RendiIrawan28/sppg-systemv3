<?php

namespace App\Livewire\V3\Beneficiaries\Periods;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\BeneficiaryPeriod;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.view'), 403);

        $query = BeneficiaryPeriod::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->when(trim($this->search) !== '', function ($query): void {
                $search = trim($this->search);
                $query->where(fn ($query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "%{$search}%"));
            })
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status));

        return view('livewire.v3.beneficiaries.periods.index', [
            ...$this->shellData($unit),
            'periods' => $query->orderByDesc('start_date')->paginate(12),
            'activePeriod' => BeneficiaryPeriod::query()->where('sppg_unit_id', $unit->getKey())->where('status', 'active')->first(),
            'periodCount' => BeneficiaryPeriod::query()->where('sppg_unit_id', $unit->getKey())->count(),
        ])->layout('layouts.v3', ['title' => 'Periode Penerima']);
    }
}
