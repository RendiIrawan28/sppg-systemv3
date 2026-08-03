<?php

namespace App\Livewire\V3\Security;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\SecurityShift;
use App\Support\V3\SecurityShiftAccess;
use Illuminate\Support\Carbon;
use Livewire\Component;

class ShiftShow extends Component
{
    use InteractsWithV3Shell;

    public int $shiftId;

    public function mount(SecurityShift $shift): void
    {
        abort_unless($this->allowed('security.view'), 403);
        abort_unless((int) $shift->sppg_unit_id === (int) $this->currentUnit()->getKey(), 404);
        abort_unless(app(SecurityShiftAccess::class)->canView(auth()->user(), $shift), 403);

        $this->shiftId = $shift->getKey();
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $shift = SecurityShift::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->with('reports')
            ->findOrFail($this->shiftId);

        abort_unless(app(SecurityShiftAccess::class)->canView(auth()->user(), $shift), 403);

        return view('livewire.v3.security.shift-show', [
            ...$this->shellData($unit),
            'shift' => $shift,
            'periods' => $this->periods($shift),
        ])->layout('layouts.v3', ['title' => 'Rincian Shift Keamanan']);
    }

    /** @return array<int, array<string, mixed>> */
    private function periods(SecurityShift $shift): array
    {
        return collect(range(1, $shift->reports_expected))
            ->map(function (int $sequence) use ($shift): array {
                $report = $shift->reports->firstWhere('sequence_number', $sequence);
                $dueAt = $shift->started_at->copy()->addHours(SecurityShift::REPORT_INTERVAL_HOURS * $sequence);
                $deadline = $sequence < $shift->reports_expected
                    ? $shift->started_at->copy()->addHours(SecurityShift::REPORT_INTERVAL_HOURS * ($sequence + 1))
                    : $shift->reportingDeadline();

                return [
                    'sequence' => $sequence,
                    'due_at' => $dueAt,
                    'report' => $report,
                    'status' => $this->periodStatus($report?->reported_at, $dueAt, $deadline),
                ];
            })
            ->all();
    }

    private function periodStatus(?Carbon $reportedAt, Carbon $dueAt, Carbon $deadline): array
    {
        if ($reportedAt) {
            $onTime = $reportedAt->lessThanOrEqualTo($deadline);

            return [
                'label' => $onTime ? 'Dilaporkan' : 'Terlambat',
                'class' => $onTime ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700',
            ];
        }

        $waiting = now()->lessThan($dueAt);

        return [
            'label' => $waiting ? 'Menunggu' : 'Tidak dilaporkan',
            'class' => $waiting ? 'bg-slate-100 text-slate-600' : 'bg-rose-50 text-rose-700',
        ];
    }
}
