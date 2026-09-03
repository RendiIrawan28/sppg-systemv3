<?php

namespace App\Livewire\V3\Operations;

use App\Enums\DistributionRunState;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Livewire\V3\Concerns\FiltersByWorkDate;
use App\Models\CleaningArea;
use App\Models\DistributionRun;
use App\Services\CleaningScheduleService;
use App\Support\V3\OperationalModuleRegistry;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithV3Shell;
    use FiltersByWorkDate;
    use WithPagination;

    public string $module;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'mulai')]
    public string $periodStart = '';

    #[Url(as: 'selesai')]
    public string $periodEnd = '';

    public function mount(string $module, OperationalModuleRegistry $registry): void
    {
        $registry->get($module);
        $this->module = $module;
        if ($module === 'kebersihan') {
            $this->periodStart = $this->periodStart ?: now()->startOfWeek()->toDateString();
            $this->periodEnd = $this->periodEnd ?: now()->startOfWeek()->addWeek()->addDays(4)->toDateString();
        }
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
        $selectedDate = $this->selectedWorkDate();
        if ($this->module === 'kebersihan' && $selectedDate === now()->toDateString()
            && $actor->can('cleaning.view')) {
            app(CleaningScheduleService::class)->ensureForDate($unit, now()->toDateString(), $actor);
        }

        $query = $model::query()->where('sppg_unit_id', $unit->getKey());

        if ($this->module === 'pencucian') {
            $query->with(['containerCollectionRun', 'distributionRun']);
        }

        if ($this->module === 'kebersihan') {
            $query->with(['cleaningArea', 'petugas', 'checklistItems']);
        }

        if ($this->module === 'distribusi'
            && $actor->can('distribution.update')
            && ! $actor->can('distribution.approve')) {
            $query->where(function ($query) use ($actor): void {
                $query->where('state', DistributionRunState::Planned->value)
                    ->orWhere('petugas_id', $actor->getKey());
            });
        }

        $terminalStates = match ($this->module) {
            'distribusi' => ['returned', 'cancelled'],
            'pencucian', 'kebersihan' => ['completed', 'ready'],
            default => ['completed', 'cancelled'],
        };
        $attentionRecords = (clone $query)
            ->whereDate($definition['date'], '!=', $selectedDate)
            ->whereNotIn('state', $terminalStates)
            ->latest($definition['date'])
            ->limit(10)
            ->get();

        $selectedDateQuery = (clone $query)
            ->whereDate($definition['date'], $selectedDate);
        $washingSummary = $this->module === 'pencucian' ? [
            'waiting' => (clone $selectedDateQuery)->where('state', 'planned')->count(),
            'washing' => (clone $selectedDateQuery)->whereIn('state', ['received', 'washing'])->count(),
            'ready' => (clone $selectedDateQuery)->whereIn('state', ['completed', 'ready'])->count(),
        ] : [];

        $records = $selectedDateQuery
            ->when($this->search !== '', function ($query) use ($definition): void {
                $query->where(function ($query) use ($definition): void {
                    $query->where($definition['number'], 'like', '%'.$this->search.'%');

                    if ($this->module === 'distribusi') {
                        $query->orWhere('route_name', 'like', '%'.$this->search.'%')
                            ->orWhere('driver_name', 'like', '%'.$this->search.'%');
                    }

                    if ($this->module === 'pencucian') {
                        $query->orWhereHas('containerCollectionRun', function ($run): void {
                            $run->where('run_number', 'like', '%'.$this->search.'%')
                                ->orWhere('driver_name_snapshot', 'like', '%'.$this->search.'%');
                        });
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
            if ($actor->can('distribution.update')) {
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
            }

            $availableCount = DistributionRun::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->whereDate('distribution_date', $this->selectedWorkDate())
                ->where('state', DistributionRunState::Planned->value)
                ->count();
        }

        $cleaningAreas = $this->module === 'kebersihan'
            ? CleaningArea::query()->where('sppg_unit_id', $unit->getKey())->where('is_active', true)->orderBy('name')->get()
            : collect();

        return view('livewire.v3.operations.index', [
            ...$this->shellData($unit),
            'definition' => $definition,
            'records' => $records,
            'canCreate' => $this->allowed($definition['permission'].'.create'),
            'activeRoute' => $activeRoute,
            'availableCount' => $availableCount,
            'attentionRecords' => $attentionRecords,
            'washingSummary' => $washingSummary,
            'periodStart' => $this->periodStart,
            'periodEnd' => $this->periodEnd,
            'cleaningAreas' => $cleaningAreas,
            'selectedDate' => $selectedDate,
        ])->layout('layouts.v3', ['title' => $definition['label']]);
    }
}
