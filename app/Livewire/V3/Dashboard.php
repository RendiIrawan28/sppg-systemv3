<?php

namespace App\Livewire\V3;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Services\V3\DashboardSummary;
use Livewire\Component;

class Dashboard extends Component
{
    use InteractsWithV3Shell;

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('dashboard.view'), 403);

        return view('livewire.v3.dashboard', [
            ...$this->shellData($unit),
            ...app(DashboardSummary::class)->for(auth()->user(), $unit),
        ])->layout('layouts.v3', ['title' => 'Dashboard']);
    }
}
