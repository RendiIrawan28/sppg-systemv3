<?php

namespace App\Livewire\V3\Field\Plans;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\FieldDistributionPlan;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('field_planning.view'), 403);
        $plans = FieldDistributionPlan::query()
            ->with(['menu', 'beneficiaryPeriod'])
            ->where('sppg_unit_id', $unit->getKey())
            ->when($this->search !== '', fn ($query) => $query->where('plan_number', 'like', '%'.$this->search.'%'))
            ->latest('distribution_date')->latest('id')->paginate(15);

        return view('livewire.v3.field.plans.index', [
            ...$this->shellData($unit), 'plans' => $plans,
            'canCreate' => $this->allowed('field_planning.create'),
        ])->layout('layouts.v3', ['title' => 'Rencana Lapangan']);
    }
}
