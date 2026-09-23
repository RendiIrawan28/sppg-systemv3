<?php

namespace App\Livewire\V3\Monitoring;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Services\V3\OperationalMonitoringService;
use App\Support\V3\MonitoringNavigation;
use Carbon\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

class Landing extends Component
{
    use InteractsWithV3Shell;

    #[Url(as: 'date', history: true)]
    public string $workDate = '';

    public string $lastRefreshedAt = '';

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('monitoring_operasional.view'), 403);

        $this->normalizeDate();
    }

    public function refreshData(): void
    {
        $this->normalizeDate();
    }

    public function useToday(): void
    {
        $this->workDate = now()->toDateString();
        $this->refreshData();
    }

    public function previousDay(): void
    {
        $this->normalizeDate();
        $this->workDate = Carbon::parse($this->workDate)->subDay()->toDateString();
        $this->refreshData();
    }

    public function nextDay(): void
    {
        $this->normalizeDate();
        $this->workDate = Carbon::parse($this->workDate)->addDay()->toDateString();
        $this->refreshData();
    }

    public function render(OperationalMonitoringService $service)
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('monitoring_operasional.view'), 403);
        $this->normalizeDate();

        $shell = $this->shellData($unit);
        $shell['navigation'] = app(MonitoringNavigation::class)->for($this->workDate, landing: true);

        $data = $service->summaryFor($unit, $this->workDate);
        $this->lastRefreshedAt = now()->format('H:i:s');

        return view('livewire.v3.monitoring.landing', [
            ...$shell,
            ...$data,
        ])->layout('layouts.v3', ['title' => 'Monitoring Operasional']);
    }

    private function normalizeDate(): void
    {
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
