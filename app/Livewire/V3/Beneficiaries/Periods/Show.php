<?php

namespace App\Livewire\V3\Beneficiaries\Periods;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\BeneficiaryPeriod;
use Livewire\Component;

class Show extends Component
{
    use InteractsWithV3Shell;

    public int $periodId;

    public function mount(BeneficiaryPeriod $period): void
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.view'), 403);
        abort_unless((int) $period->sppg_unit_id === (int) $unit->getKey(), 404);
        $this->periodId = $period->getKey();
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $period = BeneficiaryPeriod::query()
            ->with([
                'destinations' => fn ($query) => $query->where('is_active', true)->with('categoryTotals'),
                'histories' => fn ($query) => $query->with('user')->limit(12),
            ])
            ->where('sppg_unit_id', $unit->getKey())
            ->findOrFail($this->periodId);

        $categorySummary = $period->categoryTotals()->exists()
            ? $period->categoryTotals()
                ->selectRaw('beneficiary_category_name_snapshot AS category_name, SUM(total_beneficiaries) AS total')
                ->groupBy('beneficiary_category_name_snapshot')
                ->orderBy('beneficiary_category_name_snapshot')
                ->get()
            : $period->members()
                ->where('is_active', true)
                ->selectRaw("COALESCE(beneficiary_category_name_snapshot, 'Tanpa kategori') AS category_name, COUNT(*) AS total")
                ->groupBy('beneficiary_category_name_snapshot')
                ->orderBy('beneficiary_category_name_snapshot')
                ->get();

        return view('livewire.v3.beneficiaries.periods.show', [
            ...$this->shellData($unit),
            'period' => $period,
            'categorySummary' => $categorySummary,
        ])->layout('layouts.v3', ['title' => 'Ringkasan Jumlah Penerima']);
    }
}
