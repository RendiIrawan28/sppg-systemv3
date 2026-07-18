<?php

namespace App\Livewire\V3\Nutrition\Requirements;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\NutritionRequirementPlan;
use App\Services\NutritionRequirementFromFieldPlanService;
use Livewire\Component;
use Throwable;

class Show extends Component
{
    use InteractsWithV3Shell;

    public int $planId;
    public ?string $actionMessage = null;

    public function mount(NutritionRequirementPlan $plan): void
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('nutrition.view'), 403);
        abort_unless((int) $plan->sppg_unit_id === (int) $unit->getKey(), 404);
        abort_unless($plan->field_distribution_plan_id !== null, 404);
        $this->planId = $plan->getKey();
    }

    public function recalculate(): void
    {
        abort_unless($this->allowed('nutrition.manage'), 403);

        try {
            $plan = $this->plan()->load('fieldDistributionPlan');
            $requirement = app(NutritionRequirementFromFieldPlanService::class)->generate(
                $plan->fieldDistributionPlan,
                auth()->user(),
                (float) $plan->buffer_percent,
            );
            $this->actionMessage = "Kebutuhan {$requirement->plan_number} dan draft pengadaan berhasil diperbarui.";
            $this->resetErrorBag();
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('action', $exception->getMessage());
        }
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $plan = $this->plan()->load([
            'menu',
            'items.ingredient',
            'fieldDistributionPlan',
            'procurementRequest',
        ]);

        return view('livewire.v3.nutrition.requirements.show', [
            ...$this->shellData($unit),
            'plan' => $plan,
        ])->layout('layouts.v3', ['title' => 'Rincian Kebutuhan & Pengadaan']);
    }

    private function plan(): NutritionRequirementPlan
    {
        return NutritionRequirementPlan::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->findOrFail($this->planId);
    }
}
