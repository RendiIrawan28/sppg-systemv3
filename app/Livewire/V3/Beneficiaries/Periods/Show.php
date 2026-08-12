<?php

namespace App\Livewire\V3\Beneficiaries\Periods;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\BeneficiaryPeriod;
use App\Services\BeneficiaryPeriodSnapshotService;
use App\Services\BeneficiaryPeriodWorkflowService;
use Livewire\Component;
use Throwable;

class Show extends Component
{
    use InteractsWithV3Shell;

    public int $periodId;

    public ?int $sourcePeriodId = null;

    public string $workflowNotes = '';

    public ?string $actionMessage = null;

    public function mount(BeneficiaryPeriod $period): void
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.view'), 403);
        abort_unless((int) $period->sppg_unit_id === (int) $unit->getKey(), 404);
        $this->periodId = $period->getKey();
    }

    public function refreshSnapshot(BeneficiaryPeriodSnapshotService $service): void
    {
        $this->runAction(function () use ($service): string {
            $period = $this->period();
            $this->authorizeUpdate($period);
            abort_if($period->categoryTotals()->exists(), 422, 'Periode input manual tidak menggunakan snapshot nama penerima.');
            $result = $service->importCurrentMaster($period, auth()->user(), true);

            return "Snapshot diperbarui: {$result['destinationCount']} tujuan dan {$result['memberCount']} penerima.";
        });
    }

    public function copyPeriod(BeneficiaryPeriodSnapshotService $service): void
    {
        $this->validate(['sourcePeriodId' => ['required', 'integer']]);

        $this->runAction(function () use ($service): string {
            $target = $this->period();
            $this->authorizeUpdate($target);
            $source = BeneficiaryPeriod::query()
                ->where('sppg_unit_id', $target->sppg_unit_id)
                ->whereKeyNot($target->getKey())
                ->findOrFail($this->sourcePeriodId);
            $result = $service->copyPeriod($source, $target, auth()->user());

            return "Periode berhasil disalin: {$result['destinationCount']} tujuan dan {$result['memberCount']} penerima.";
        });
    }

    public function promoteClasses(BeneficiaryPeriodSnapshotService $service): void
    {
        $this->runAction(function () use ($service): string {
            $period = $this->period();
            $this->authorizeUpdate($period);
            abort_if($period->categoryTotals()->exists(), 422, 'Kenaikan kelas hanya tersedia untuk data penerima berdasarkan nama.');
            $result = $service->promoteClasses($period, auth()->user());

            return "Kenaikan kelas selesai: {$result['updated']} naik kelas, {$result['graduated']} dinonaktifkan, {$result['skipped']} dilewati.";
        });
    }

    public function workflow(string $action, BeneficiaryPeriodWorkflowService $service): void
    {
        abort_unless(in_array($action, ['submit', 'revision', 'approve', 'activate', 'close'], true), 404);

        if ($action === 'revision') {
            $this->validate(['workflowNotes' => ['required', 'string', 'max:2000']]);
        } else {
            $this->validate(['workflowNotes' => ['nullable', 'string', 'max:2000']]);
        }

        $this->runAction(function () use ($action, $service): string {
            $period = $this->period();
            $notes = trim($this->workflowNotes) ?: null;

            match ($action) {
                'submit' => $service->submit($period, auth()->user(), $notes),
                'revision' => $service->requestRevision($period, auth()->user(), (string) $notes),
                'approve' => $service->approve($period, auth()->user(), $notes),
                'activate' => $service->activate($period, auth()->user(), $notes),
                'close' => $service->close($period, auth()->user(), $notes),
            };

            $this->workflowNotes = '';

            return match ($action) {
                'submit' => 'Periode berhasil diajukan.',
                'revision' => 'Periode dikembalikan untuk revisi.',
                'approve' => 'Periode berhasil disetujui dan dikunci.',
                'activate' => 'Periode berhasil diaktifkan.',
                'close' => 'Periode berhasil ditutup.',
            };
        });
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $period = BeneficiaryPeriod::query()
            ->with([
                'destinations' => fn ($query) => $query->where('is_active', true)->with('categoryTotals')->withCount('activeMembers'),
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
            'inputMode' => $period->categoryTotals()->exists() ? 'manual' : 'master',
            'sourcePeriods' => BeneficiaryPeriod::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->whereKeyNot($period->getKey())
                ->whereHas('destinations')
                ->when(
                    $period->categoryTotals()->exists(),
                    fn ($query) => $query->whereHas('categoryTotals'),
                    fn ($query) => $query->whereDoesntHave('categoryTotals'),
                )
                ->orderByDesc('start_date')
                ->limit(20)
                ->get(['id', 'code', 'name', 'start_date', 'end_date', 'status']),
        ])->layout('layouts.v3', ['title' => 'Ringkasan Jumlah Penerima']);
    }

    private function period(): BeneficiaryPeriod
    {
        return BeneficiaryPeriod::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->findOrFail($this->periodId);
    }

    private function authorizeUpdate(BeneficiaryPeriod $period): void
    {
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.update'), 403);
        abort_unless($period->isEditable(), 422, 'Periode yang sudah diajukan atau dikunci tidak dapat diubah.');
    }

    private function runAction(callable $callback): void
    {
        $this->resetErrorBag('action');

        try {
            $this->actionMessage = $callback();
        } catch (Throwable $exception) {
            report($exception);
            $this->actionMessage = null;
            $this->addError('action', $exception->getMessage());
        }
    }
}
