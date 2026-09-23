<?php

namespace App\Livewire\V3\Monitoring;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Services\V3\OperationalMonitoringService;
use Carbon\Carbon;
use Livewire\Component;

class Operational extends Component
{
    use InteractsWithV3Shell;

    public string $workDate = '';

    public string $activeTab = 'overview';

    public string $lastRefreshedAt = '';

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('monitoring_operasional.view'), 403);

        $this->workDate = now()->toDateString();
        $this->lastRefreshedAt = now()->format('H:i:s');
    }

    public function selectTab(string $tab): void
    {
        abort_unless(in_array($tab, ['overview', 'warehouse', 'preparation', 'processing'], true), 404);
        $this->activeTab = $tab;
    }

    public function refreshData(): void
    {
        $this->validate([
            'workDate' => ['required', 'date_format:Y-m-d'],
        ]);

        $this->lastRefreshedAt = now()->format('H:i:s');
    }

    public function useToday(): void
    {
        $this->workDate = now()->toDateString();
        $this->refreshData();
    }

    public function previousDay(): void
    {
        $this->workDate = Carbon::parse($this->workDate)->subDay()->toDateString();
        $this->refreshData();
    }

    public function nextDay(): void
    {
        $this->workDate = Carbon::parse($this->workDate)->addDay()->toDateString();
        $this->refreshData();
    }

    public function render(OperationalMonitoringService $service)
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('monitoring_operasional.view'), 403);

        $this->validate([
            'workDate' => ['required', 'date_format:Y-m-d'],
        ]);

        return view('livewire.v3.monitoring.operational', [
            ...$this->shellData($unit),
            ...$service->for($unit, $this->workDate),
        ])->layout('layouts.v3', ['title' => 'Monitoring Operasional']);
    }
}
