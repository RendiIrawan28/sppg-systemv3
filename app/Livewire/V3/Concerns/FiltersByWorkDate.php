<?php

namespace App\Livewire\V3\Concerns;

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

trait FiltersByWorkDate
{
    #[Url(as: 'tanggal', history: true)]
    public string $workDate = '';

    public function previousWorkDate(): void
    {
        $this->workDate = CarbonImmutable::parse($this->selectedWorkDate())->subDay()->toDateString();
        $this->workDateWasChanged();
    }

    public function nextWorkDate(): void
    {
        $this->workDate = CarbonImmutable::parse($this->selectedWorkDate())->addDay()->toDateString();
        $this->workDateWasChanged();
    }

    public function useTodayWorkDate(): void
    {
        $this->workDate = now()->toDateString();
        $this->workDateWasChanged();
    }

    public function updatedWorkDate(): void
    {
        $this->workDateWasChanged();
    }

    protected function selectedWorkDate(): string
    {
        if (blank($this->workDate)) {
            $this->workDate = now()->toDateString();
        }

        return $this->workDate;
    }

    private function resetWorkDatePagination(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    private function workDateWasChanged(): void
    {
        $this->resetWorkDatePagination();

        if (method_exists($this, 'afterWorkDateChanged')) {
            $this->afterWorkDateChanged();
        }
    }
}
