<?php

namespace App\Livewire\V3\Operations;

use App\Enums\DistributionRunState;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\DistributionRun;
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
        $actor = auth()->user();
        $query = $model::query()->where('sppg_unit_id', $unit->getKey());

        if ($this->module === 'distribusi' && ! $actor->can('distribution.approve')) {
            $query->where(function ($query) use ($actor): void {
                $query->where('state', DistributionRunState::Planned->value)
                    ->orWhere('petugas_id', $actor->getKey());
            });
        }

        $records = $query
            ->when($this->search !== '', function ($query) use ($definition): void {
                $query->where(function ($query) use ($definition): void {
                    $query->where($definition['number'], 'like', '%'.$this->search.'%');

                    if ($this->module === 'distribusi') {
                        $query->orWhere('route_name', 'like', '%'.$this->search.'%')
                            ->orWhere('driver_name', 'like', '%'.$this->search.'%');
                    }
                });
            })
            ->when($this->module === 'distribusi', function ($query) use ($actor): void {
                $query->orderByRaw(
                    'CASE WHEN petugas_id = ? AND state IN (?, ?, ?, ?) THEN 0 WHEN state = ? THEN 1 ELSE 2 END',
                    [
                        $actor->getKey(),
                        DistributionRunState::Assigned->value,
                        DistributionRunState::Loaded->value,
                        DistributionRunState::Departed->value,
                        DistributionRunState::DestinationsCompleted->value,
                        DistributionRunState::Planned->value,
                    ],
                );
            })
            ->latest($definition['date'])
            ->latest('id')
            ->paginate(15);

        $activeRoute = null;
        $availableCount = null;

        if ($this->module === 'distribusi') {
            $activeRoute = DistributionRun::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->where('petugas_id', $actor->getKey())
                ->whereIn('state', [
                    DistributionRunState::Assigned->value,
                    DistributionRunState::Loaded->value,
                    DistributionRunState::Departed->value,
                    DistributionRunState::DestinationsCompleted->value,
                ])
                ->latest('id')
                ->first();

            $availableCount = DistributionRun::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->where('state', DistributionRunState::Planned->value)
                ->count();
        }

        return view('livewire.v3.operations.index', [
            ...$this->shellData($unit),
            'definition' => $definition,
            'records' => $records,
            'canCreate' => $this->allowed($definition['permission'].'.create'),
            'activeRoute' => $activeRoute,
            'availableCount' => $availableCount,
        ])->layout('layouts.v3', ['title' => $definition['label']]);
    }
}
