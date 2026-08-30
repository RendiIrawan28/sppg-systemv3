<?php

namespace App\Livewire\V3\Field\Incidents;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Livewire\V3\Concerns\FiltersByWorkDate;
use App\Models\FieldIncident;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithV3Shell;
    use FiltersByWorkDate;
    use WithPagination;

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('field_incidents.view'), 403);
        $incidents = FieldIncident::query()->with('responsibleUser')->where('sppg_unit_id', $unit->getKey())
            ->whereDate('incident_date', $this->selectedWorkDate())
            ->latest('incident_date')->latest('occurred_at')->paginate(15);

        return view('livewire.v3.field.incidents.index', [
            ...$this->shellData($unit), 'incidents' => $incidents,
            'canCreate' => $this->allowed('field_incidents.create'),
        ])->layout('layouts.v3', ['title' => 'Insiden Lapangan']);
    }
}
