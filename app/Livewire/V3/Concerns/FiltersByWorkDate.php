<?php

namespace App\Livewire\V3\Concerns;

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

trait FiltersByWorkDate
{
    #[Url(as: 'tanggal', history: true)]
    public string $workDate = '';

    public function selectWorkDate(string $date): void
    {
        $this->workDate = $date;
        $this->workDateWasChanged();
    }

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
        $normalized = $this->normalizeWorkDate($this->workDate);
        if ($normalized === null) {
            $this->workDate = now()->toDateString();
        } else {
            $this->workDate = $normalized;
        }

        return $this->workDate;
    }

    private function normalizeWorkDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                $date = false;
            }

            if ($date !== false && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }

    private function resetWorkDatePagination(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    private function workDateWasChanged(): void
    {
        $this->selectedWorkDate();
        $this->resetWorkDatePagination();

        if (method_exists($this, 'afterWorkDateChanged')) {
            $this->afterWorkDateChanged();
        }
    }
}
