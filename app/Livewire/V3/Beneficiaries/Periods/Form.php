<?php

namespace App\Livewire\V3\Beneficiaries\Periods;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\BeneficiaryPeriod;
use App\Services\BeneficiaryPeriodSnapshotService;
use App\Services\BeneficiaryPeriodWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class Form extends Component
{
    use InteractsWithV3Shell;

    public ?int $periodId = null;

    public string $code = '';

    public string $name = '';

    public string $startDate = '';

    public string $endDate = '';

    public ?int $sourcePeriodId = null;

    public string $notes = '';

    public function mount(?BeneficiaryPeriod $period = null): void
    {
        $unit = $this->currentUnit();
        $user = auth()->user();

        if ($period?->exists) {
            abort_unless($user->is_super_admin || $user->can('beneficiary_periods.update'), 403);
            abort_unless((int) $period->sppg_unit_id === (int) $unit->getKey(), 404);
            abort_unless($period->isEditable(), 403, 'Periode sudah diajukan atau dikunci.');

            $this->periodId = $period->getKey();
            $this->code = (string) $period->code;
            $this->name = (string) $period->name;
            $this->startDate = $period->start_date?->toDateString() ?? '';
            $this->endDate = $period->end_date?->toDateString() ?? '';
            $this->sourcePeriodId = $period->source_period_id;
            $this->notes = (string) $period->notes;

            return;
        }

        abort_unless($user->is_super_admin || $user->can('beneficiary_periods.create'), 403);
        $start = CarbonImmutable::today();
        $this->code = 'PM-'.$start->format('Ymd');
        $this->name = 'Periode '.$start->translatedFormat('d M Y');
        $this->startDate = $start->toDateString();
        $this->endDate = $start->addDays(13)->toDateString();
    }

    public function updatedStartDate(): void
    {
        try {
            $this->endDate = CarbonImmutable::parse($this->startDate)->addDays(13)->toDateString();
        } catch (Throwable) {
            $this->endDate = '';
        }
    }

    public function save(BeneficiaryPeriodSnapshotService $snapshotService, BeneficiaryPeriodWorkflowService $workflowService): void
    {
        $unit = $this->currentUnit();
        $permission = $this->periodId ? 'beneficiary_periods.update' : 'beneficiary_periods.create';
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can($permission), 403);

        $data = $this->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('beneficiary_periods', 'code')->where(fn ($query) => $query->where('sppg_unit_id', $unit->getKey()))->ignore($this->periodId)],
            'name' => ['required', 'string', 'max:255'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'sourcePeriodId' => [
                'nullable', 'integer',
                Rule::exists('beneficiary_periods', 'id')->where(fn ($query) => $query
                    ->where('sppg_unit_id', $unit->getKey())
                    ->whereIn('status', ['approved', 'active', 'closed'])),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $expectedEnd = CarbonImmutable::parse($data['startDate'])->addDays(13)->toDateString();
        if ($data['endDate'] !== $expectedEnd) {
            throw ValidationException::withMessages(['endDate' => 'Tanggal selesai harus tepat 14 hari sejak tanggal mulai.']);
        }

        $overlap = BeneficiaryPeriod::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->when($this->periodId, fn ($query) => $query->whereKeyNot($this->periodId))
            ->where(function ($query) use ($data): void {
                $query->whereBetween('start_date', [$data['startDate'], $data['endDate']])
                    ->orWhereBetween('end_date', [$data['startDate'], $data['endDate']])
                    ->orWhere(fn ($query) => $query->whereDate('start_date', '<=', $data['startDate'])->whereDate('end_date', '>=', $data['endDate']));
            })->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['startDate' => 'Rentang 14 hari ini bertumpang tindih dengan periode penerima lain.']);
        }

        try {
            $period = DB::transaction(function () use ($data, $unit): BeneficiaryPeriod {
                $period = $this->periodId
                    ? BeneficiaryPeriod::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($this->periodId)
                    : new BeneficiaryPeriod([
                        'sppg_unit_id' => $unit->getKey(),
                        'created_by' => auth()->id(),
                        'status' => 'draft',
                        'revision_number' => 0,
                    ]);

                abort_unless($period->isEditable(), 403, 'Periode sudah diajukan atau dikunci.');
                $period->fill([
                    'code' => trim($data['code']),
                    'name' => trim($data['name']),
                    'start_date' => $data['startDate'],
                    'end_date' => $data['endDate'],
                    'source_period_id' => $this->periodId ? $period->source_period_id : $data['sourcePeriodId'],
                    'notes' => trim($data['notes']) ?: null,
                ])->save();

                return $period;
            });

            if (! $this->periodId && $data['sourcePeriodId']) {
                $source = BeneficiaryPeriod::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($data['sourcePeriodId']);
                $snapshotService->copyPeriod($source, $period, auth()->user());
            }

            $workflowService->assertDates($period);
            $snapshotService->recalculate($period);
            session()->flash('v3.status', $this->periodId ? 'Periode penerima berhasil diperbarui.' : 'Periode 14 hari berhasil dibuat.');
            $this->redirectRoute('v3.beneficiary-periods.show', ['period' => $period], navigate: true);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('form', $exception->getMessage());
        }
    }

    public function delete(): void
    {
        abort_unless($this->periodId, 404);
        $unit = $this->currentUnit();
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.delete'), 403);

        $period = BeneficiaryPeriod::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($this->periodId);
        abort_unless($period->status === 'draft' && ! $period->distributionPlans()->exists(), 403);
        $period->delete();

        session()->flash('v3.status', 'Periode draft berhasil dihapus.');
        $this->redirectRoute('v3.beneficiary-periods.index', navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();

        return view('livewire.v3.beneficiaries.periods.form', [
            ...$this->shellData($unit),
            'sourcePeriods' => BeneficiaryPeriod::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->whereIn('status', ['approved', 'active', 'closed'])
                ->orderByDesc('start_date')
                ->get(),
        ])->layout('layouts.v3', ['title' => $this->periodId ? 'Ubah Periode Penerima' : 'Buat Periode Penerima']);
    }
}
