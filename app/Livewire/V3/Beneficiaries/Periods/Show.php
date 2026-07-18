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

    public bool $replaceExisting = false;

    public string $workflowNotes = '';

    public ?string $actionMessage = null;

    public function mount(BeneficiaryPeriod $period): void
    {
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.view'), 403);
        abort_unless((int) $period->sppg_unit_id === (int) $unit->getKey(), 404);
        $this->periodId = $period->getKey();
    }

    public function importCurrentMaster(BeneficiaryPeriodSnapshotService $service): void
    {
        $this->authorizeAction('beneficiary_periods.import');
        $this->runAction(function () use ($service): string {
            $result = $service->importCurrentMaster($this->period(), auth()->user(), $this->replaceExisting);
            $this->replaceExisting = false;

            return "Snapshot diperbarui: {$result['destinationCount']} instansi dan {$result['memberCount']} penerima diproses.";
        });
    }

    public function promoteClasses(BeneficiaryPeriodSnapshotService $service): void
    {
        $this->authorizeAction('beneficiary_periods.update');
        $this->runAction(function () use ($service): string {
            $result = $service->promoteClasses($this->period(), auth()->user());

            return "Kenaikan kelas selesai: {$result['updated']} naik, {$result['graduated']} dinonaktifkan, {$result['skipped']} dilewati.";
        });
    }

    public function submit(BeneficiaryPeriodWorkflowService $service): void
    {
        $this->authorizeAction('beneficiary_periods.submit');
        $this->runAction(function () use ($service): string {
            $service->submit($this->period(), auth()->user(), $this->nullableNotes());

            return 'Periode berhasil diajukan.';
        });
    }

    public function approve(BeneficiaryPeriodWorkflowService $service): void
    {
        $this->authorizeAction('beneficiary_periods.approve');
        $this->runAction(function () use ($service): string {
            $service->approve($this->period(), auth()->user(), $this->nullableNotes());

            return 'Periode berhasil disetujui dan dikunci.';
        });
    }

    public function requestRevision(BeneficiaryPeriodWorkflowService $service): void
    {
        $this->authorizeAction('beneficiary_periods.approve');
        $this->validate(['workflowNotes' => ['required', 'string', 'min:5', 'max:2000']]);
        $this->runAction(function () use ($service): string {
            $service->requestRevision($this->period(), auth()->user(), $this->workflowNotes);

            return 'Periode dikembalikan untuk revisi.';
        });
    }

    public function activate(BeneficiaryPeriodWorkflowService $service): void
    {
        $this->authorizeAction('beneficiary_periods.activate');
        $this->runAction(function () use ($service): string {
            $service->activate($this->period(), auth()->user(), $this->nullableNotes());

            return 'Periode sekarang aktif.';
        });
    }

    public function close(BeneficiaryPeriodWorkflowService $service): void
    {
        $this->authorizeAction('beneficiary_periods.close');
        $this->runAction(function () use ($service): string {
            $service->close($this->period(), auth()->user(), $this->nullableNotes());

            return 'Periode berhasil ditutup.';
        });
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $period = BeneficiaryPeriod::query()
            ->with([
                'destinations' => fn ($query) => $query->withCount('activeMembers'),
                'histories' => fn ($query) => $query->with('user')->limit(12),
            ])
            ->where('sppg_unit_id', $unit->getKey())
            ->findOrFail($this->periodId);

        $categorySummary = $period->members()
            ->where('is_active', true)
            ->selectRaw("COALESCE(beneficiary_category_name_snapshot, 'Tanpa kategori') AS category_name, COUNT(*) AS total")
            ->groupBy('beneficiary_category_name_snapshot')
            ->orderBy('beneficiary_category_name_snapshot')
            ->get();

        return view('livewire.v3.beneficiaries.periods.show', [
            ...$this->shellData($unit),
            'period' => $period,
            'categorySummary' => $categorySummary,
        ])->layout('layouts.v3', ['title' => 'Ringkasan Periode Penerima']);
    }

    private function period(): BeneficiaryPeriod
    {
        $unit = $this->currentUnit();

        return BeneficiaryPeriod::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($this->periodId);
    }

    private function authorizeAction(string $permission): void
    {
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can($permission), 403);
    }

    private function runAction(callable $action): void
    {
        try {
            $this->actionMessage = $action();
            $this->workflowNotes = '';
            $this->resetErrorBag();
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('action', $exception->getMessage());
        }
    }

    private function nullableNotes(): ?string
    {
        return trim($this->workflowNotes) !== '' ? trim($this->workflowNotes) : null;
    }
}
