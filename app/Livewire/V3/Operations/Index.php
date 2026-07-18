<?php

namespace App\Livewire\V3\Operations;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Support\V3\OperationalModuleRegistry;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    public string $module;

    #[Url(as: 'q')]
    public string $search = '';

    public function mount(string $module, OperationalModuleRegistry $registry): void
    {
        $registry->get($module);
        $this->module = $module;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(OperationalModuleRegistry $registry)
    {
        $unit = $this->currentUnit();
        $definition = $registry->get($this->module);
        abort_unless($this->allowed($definition['permission'].'.view'), 403);

        $model = $definition['model'];
        $records = $model::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->when($this->search !== '', function ($query) use ($definition): void {
                $query->where($definition['number'], 'like', '%'.$this->search.'%');
            })
            ->latest($definition['date'])
            ->latest('id')
            ->paginate(15);

        return view('livewire.v3.operations.index', [
            ...$this->shellData($unit),
            'definition' => $definition,
            'records' => $records,
            'canCreate' => $this->allowed($definition['permission'].'.create'),
        ])->layout('layouts.v3', ['title' => $definition['label']]);
    }
}
