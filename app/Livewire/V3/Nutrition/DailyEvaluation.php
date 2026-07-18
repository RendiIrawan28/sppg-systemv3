<?php

namespace App\Livewire\V3\Nutrition;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\MenuAcceptanceEvaluation;
use App\Models\NutritionDailyReport;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class DailyEvaluation extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('nutrition.view'), 403);

        $reports = NutritionDailyReport::query()
            ->with('menu')
            ->where('sppg_unit_id', $unit->getKey())
            ->when($this->search !== '', function ($query): void {
                $search = trim($this->search);
                $query->where(function ($query) use ($search): void {
                    $query->where('report_number', 'like', "%{$search}%")
                        ->orWhereHas('menu', fn ($query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('report_date')
            ->paginate(12);

        $evaluations = MenuAcceptanceEvaluation::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->whereIn('menu_id', $reports->getCollection()->pluck('menu_id')->filter()->unique())
            ->whereIn('evaluation_date', $reports->getCollection()->pluck('report_date')->map->toDateString()->unique())
            ->get()
            ->groupBy(fn (MenuAcceptanceEvaluation $evaluation): string => $evaluation->evaluation_date->toDateString().'|'.$evaluation->menu_id);

        return view('livewire.v3.nutrition.daily-evaluation', [
            ...$this->shellData($unit),
            'reports' => $reports,
            'evaluations' => $evaluations,
            'evaluationCount' => MenuAcceptanceEvaluation::query()->where('sppg_unit_id', $unit->getKey())->count(),
            'reportCount' => NutritionDailyReport::query()->where('sppg_unit_id', $unit->getKey())->count(),
        ])->layout('layouts.v3', ['title' => 'Evaluasi Gizi Harian']);
    }
}
