<?php

namespace App\Livewire\V3\Field;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\FieldDailyReport;
use Livewire\Component;
use Livewire\WithPagination;

class DailyReports extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('field_daily_reports.view'), 403);
        $reports = FieldDailyReport::query()->with(['plan', 'divisions', 'incidents'])
            ->where('sppg_unit_id', $unit->getKey())->latest('report_date')->paginate(15);

        return view('livewire.v3.field.daily-reports', [
            ...$this->shellData($unit), 'reports' => $reports,
        ])->layout('layouts.v3', ['title' => 'Laporan Harian Lapangan']);
    }
}
