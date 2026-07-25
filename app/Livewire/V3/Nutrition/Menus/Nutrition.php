<?php

namespace App\Livewire\V3\Nutrition\Menus;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\Menu;
use App\Services\MenuNutritionCalculator;
use App\Services\MenuNutritionWarningService;
use Livewire\Component;
use Throwable;

class Nutrition extends Component
{
    use InteractsWithV3Shell;

    public int $menuId;

    public ?string $actionMessage = null;

    public function mount(Menu $menu): void
    {
        abort_unless($this->allowed('menus.view'), 403);
        abort_unless((int) $menu->sppg_unit_id === (int) $this->currentUnit()->getKey(), 404);

        $this->menuId = $menu->getKey();
    }

    public function recalculate(): void
    {
        abort_unless($this->allowed('menus.update'), 403);

        try {
            $menu = $this->menu();
            app(MenuNutritionCalculator::class)->refresh($menu);
            $warnings = app(MenuNutritionWarningService::class)->nutritionWarnings($menu->refresh());
            $this->actionMessage = $warnings === []
                ? 'Nilai kebutuhan gizi harian berhasil dihitung ulang.'
                : 'Perhitungan selesai. '.implode(' ', $warnings);
            $this->resetErrorBag();
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('action', $exception->getMessage());
        }
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $menu = $this->menu()->load([
            'categoryTargets.category',
            'nutritionSummaries.component',
            'nutritionSummaries.category',
        ]);

        return view('livewire.v3.nutrition.menus.nutrition', [
            ...$this->shellData($unit),
            'menu' => $menu,
            'canRecalculate' => $menu->isEditable() && $this->allowed('menus.update'),
        ])->layout('layouts.v3', ['title' => 'Hasil Kebutuhan Gizi Harian']);
    }

    private function menu(): Menu
    {
        return Menu::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->findOrFail($this->menuId);
    }
}
