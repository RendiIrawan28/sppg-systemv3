<?php

namespace App\Livewire\V3\Monitoring;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Services\V3\OperationalMonitoringService;
use App\Support\V3\MonitoringNavigation;
use Carbon\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Operational extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    private const VALID_TABS = ['overview', 'warehouse', 'preparation', 'processing', 'portioning', 'distribution'];

    #[Url(as: 'date', history: true)]
    public string $workDate = '';

    #[Url(as: 'tab', history: true)]
    public string $activeTab = 'overview';

    public string $lastRefreshedAt = '';

    public bool $loadError = false;

    public function updatedWorkDate(): void
    {
        $this->refreshData();
    }

    public function updatedPaginators(): void
    {
        $this->dispatch('close-monitoring-documentation');
    }

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('monitoring_operasional.view'), 403);

        $this->normalizeQueryState();
    }

    public function selectTab(string $tab): void
    {
        $this->activeTab = in_array($tab, [...self::VALID_TABS, ...array_keys(MonitoringNavigation::FINAL_MODULES)], true) ? $tab : 'overview';
        $this->resetPage('monitoringPage');
        $this->resetPage('monitoringAbsentPage');
        $this->dispatch('close-monitoring-documentation');
    }

    public function refreshData(): void
    {
        $this->normalizeQueryState();
        $this->resetPage('monitoringPage');
        $this->resetPage('monitoringAbsentPage');
        $this->dispatch('close-monitoring-documentation');
    }

    public function useToday(): void
    {
        $this->workDate = now()->toDateString();
        $this->refreshData();
    }

    public function previousDay(): void
    {
        $this->normalizeQueryState();
        $this->workDate = Carbon::parse($this->workDate)->subDay()->toDateString();
        $this->refreshData();
    }

    public function nextDay(): void
    {
        $this->normalizeQueryState();
        $this->workDate = Carbon::parse($this->workDate)->addDay()->toDateString();
        $this->refreshData();
    }

    public function render(OperationalMonitoringService $service)
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('monitoring_operasional.view'), 403);

        $this->normalizeQueryState();

        $shell = $this->shellData($unit);
        $shell['navigation'] = app(MonitoringNavigation::class)->for($this->workDate, $this->activeTab);

        try {
            $data = $service->forTab($unit, $this->workDate, $this->activeTab);
            $this->loadError = false;
            $this->lastRefreshedAt = now()->format('H:i:s');
        } catch (\Throwable $exception) {
            report($exception);
            $data = [];
            $this->loadError = true;
        }

        return view('livewire.v3.monitoring.operational', [
            ...$shell,
            ...$data,
        ])->layout('layouts.v3', ['title' => 'Monitoring Operasional']);
    }

    private function normalizeQueryState(): void
    {
        $this->activeTab = in_array($this->activeTab, [...self::VALID_TABS, ...array_keys(MonitoringNavigation::FINAL_MODULES)], true)
            ? $this->activeTab
            : 'overview';

        try {
            $date = Carbon::createFromFormat('!Y-m-d', trim($this->workDate));
        } catch (\Throwable) {
            $date = false;
        }

        $this->workDate = $date !== false && $date->format('Y-m-d') === trim($this->workDate)
            ? $date->toDateString()
            : now()->toDateString();
    }
}
