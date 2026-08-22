<?php

namespace App\Livewire\V3\Nutrition\Requirements;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\MenuCycleDay;
use App\Models\NutritionRequirementPlan;
use App\Services\NutritionRequirementFromBeneficiaryPeriodService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public function mount(): void
    {
        $this->currentUnit();
        $this->authorizeView();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function generate(int $menuCycleDayId): void
    {
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('nutrition.manage'), 403);

        try {
            $day = MenuCycleDay::query()
                ->whereHas('cycle', fn ($query) => $query->where('sppg_unit_id', $this->currentUnit()->getKey()))
                ->findOrFail($menuCycleDayId);
            $plan = app(NutritionRequirementFromBeneficiaryPeriodService::class)
                ->generate($day, auth()->user());
            session()->flash('v3.status', "Kebutuhan {$plan->plan_number} dihitung dari Master Penerima dan draft pengadaan disinkronkan.");
            $this->redirectRoute('v3.nutrition.requirements.show', $plan, navigate: true);
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('generate', $exception->getMessage());
        }
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $this->authorizeView();

        $base = NutritionRequirementPlan::query()
            ->where('sppg_unit_id', $unit->getKey());

        $plans = (clone $base)
            ->with(['menu', 'fieldDistributionPlan', 'beneficiaryPeriod', 'menuCycleDay.cycle', 'procurementRequest'])
            ->when(trim($this->search) !== '', function ($query): void {
                $search = trim($this->search);
                $query->where(fn ($query) => $query
                    ->where('plan_number', 'like', "%{$search}%")
                    ->orWhereHas('menu', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('fieldDistributionPlan', fn ($query) => $query->where('plan_number', 'like', "%{$search}%")));
            })
            ->orderByDesc('requirement_date')
            ->paginate(15);

        return view('livewire.v3.nutrition.requirements.index', [
            ...$this->shellData($unit),
            'plans' => $plans,
            'planCount' => (clone $base)->count(),
            'totalWeight' => (float) (clone $base)->sum('total_weight_kg'),
            'readyCount' => (clone $base)->whereHas('procurementRequest')->count(),
            'menuDays' => MenuCycleDay::query()
                ->with(['menu', 'cycle.beneficiaryPeriod'])
                ->whereHas('cycle', fn ($query) => $query
                    ->where('sppg_unit_id', $unit->getKey())
                    ->whereIn('status', ['approved', 'active']))
                ->whereDate('service_date', '>=', today()->subDays(7))
                ->orderBy('service_date')
                ->limit(30)
                ->get(),
        ])->layout('layouts.v3', ['title' => 'Kebutuhan & Pengadaan']);
    }

    private function authorizeView(): void
    {
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('nutrition.view'), 403);
    }
}
