<?php

namespace App\Livewire\V3\Beneficiaries\Periods;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\BeneficiaryCategory;
use App\Models\BeneficiaryPeriod;
use App\Models\Posyandu;
use App\Models\School;
use App\Services\BeneficiaryPeriodAggregateService;
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

    public string $notes = '';

    /** @var array<int, array{destination_key: string, counts: array<int|string, int|string|null>}> */
    public array $destinations = [];

    public function mount(?BeneficiaryPeriod $period = null): void
    {
        $unit = $this->currentUnit();
        $user = auth()->user();

        if ($period?->exists) {
            abort_unless($user->is_super_admin || $user->can('beneficiary_periods.update'), 403);
            abort_unless((int) $period->sppg_unit_id === (int) $unit->getKey(), 404);

            $period->load([
                'destinations' => fn ($query) => $query->where('is_active', true)->with(['categoryTotals', 'members']),
            ]);
            $this->periodId = $period->getKey();
            $this->code = (string) $period->code;
            $this->name = (string) $period->name;
            $this->startDate = $period->start_date?->toDateString() ?? '';
            $this->endDate = $period->end_date?->toDateString() ?? '';
            $this->notes = (string) $period->notes;
            $this->destinations = $period->destinations->map(function ($destination): array {
                $counts = $destination->categoryTotals->isNotEmpty()
                    ? $destination->categoryTotals->pluck('total_beneficiaries', 'beneficiary_category_id')->all()
                    : $destination->members
                        ->where('is_active', true)
                        ->whereNotNull('beneficiary_category_id')
                        ->groupBy('beneficiary_category_id')
                        ->map->count()
                        ->all();

                return [
                    'destination_key' => "{$destination->destination_type}:{$destination->destination_id}",
                    'counts' => $counts,
                ];
            })->values()->all();

            if ($this->destinations === []) {
                $this->addDestination();
            }

            return;
        }

        abort_unless($user->is_super_admin || $user->can('beneficiary_periods.create'), 403);
        $start = CarbonImmutable::today();
        $this->code = 'JP-'.$start->format('Ymd');
        $this->name = 'Jumlah Penerima '.$start->translatedFormat('d M Y');
        $this->startDate = $start->toDateString();
        $this->endDate = $start->addDays(13)->toDateString();
        $this->addDestination();
    }

    public function addDestination(): void
    {
        $this->destinations[] = ['destination_key' => '', 'counts' => []];
    }

    public function removeDestination(int $index): void
    {
        unset($this->destinations[$index]);
        $this->destinations = array_values($this->destinations);

        if ($this->destinations === []) {
            $this->addDestination();
        }
    }

    public function updatedStartDate(): void
    {
        try {
            $this->endDate = CarbonImmutable::parse($this->startDate)->addDays(13)->toDateString();
        } catch (Throwable) {
            $this->endDate = '';
        }
    }

    public function save(BeneficiaryPeriodAggregateService $aggregateService): void
    {
        $unit = $this->currentUnit();
        $permission = $this->periodId ? 'beneficiary_periods.update' : 'beneficiary_periods.create';
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can($permission), 403);

        $destinationKeys = array_keys($this->destinationOptions($unit->getKey()));
        $categoryIds = BeneficiaryCategory::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $data = $this->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('beneficiary_periods', 'code')->where(fn ($query) => $query->where('sppg_unit_id', $unit->getKey()))->ignore($this->periodId)],
            'name' => ['required', 'string', 'max:255'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.destination_key' => ['required', 'string', 'distinct', Rule::in($destinationKeys)],
            'destinations.*.counts' => ['array'],
            'destinations.*.counts.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        foreach ($data['destinations'] as $index => $destination) {
            foreach (array_keys($destination['counts'] ?? []) as $categoryId) {
                if (! in_array((string) $categoryId, $categoryIds, true)) {
                    throw ValidationException::withMessages([
                        "destinations.{$index}.counts" => 'Kategori penerima tidak valid.',
                    ]);
                }
            }
        }

        $expectedEnd = CarbonImmutable::parse($data['startDate'])->addDays(13)->toDateString();
        if ($data['endDate'] !== $expectedEnd) {
            throw ValidationException::withMessages(['endDate' => 'Tanggal selesai harus tepat 14 hari sejak tanggal mulai.']);
        }

        try {
            $period = DB::transaction(function () use ($data, $unit, $aggregateService): BeneficiaryPeriod {
                BeneficiaryPeriod::query()
                    ->where('sppg_unit_id', $unit->getKey())
                    ->where('status', 'active')
                    ->when($this->periodId, fn ($query) => $query->whereKeyNot($this->periodId))
                    ->where(function ($query) use ($data): void {
                        $query->whereBetween('start_date', [$data['startDate'], $data['endDate']])
                            ->orWhereBetween('end_date', [$data['startDate'], $data['endDate']])
                            ->orWhere(fn ($query) => $query
                                ->whereDate('start_date', '<=', $data['startDate'])
                                ->whereDate('end_date', '>=', $data['endDate']));
                    })
                    ->update(['status' => 'closed', 'closed_at' => now()]);

                $period = $this->periodId
                    ? BeneficiaryPeriod::query()->where('sppg_unit_id', $unit->getKey())->findOrFail($this->periodId)
                    : new BeneficiaryPeriod([
                        'sppg_unit_id' => $unit->getKey(),
                        'created_by' => auth()->id(),
                        'revision_number' => 0,
                    ]);

                $period->fill([
                    'code' => trim($data['code']),
                    'name' => trim($data['name']),
                    'start_date' => $data['startDate'],
                    'end_date' => $data['endDate'],
                    'notes' => trim($data['notes']) ?: null,
                ])->save();

                return $aggregateService->save($period, $data['destinations'], auth()->user());
            });

            session()->flash('v3.status', 'Jumlah penerima berhasil disimpan dan langsung aktif.');
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
        abort_unless(! $period->distributionPlans()->exists() && ! $period->menuCycles()->exists(), 403);
        $period->delete();

        session()->flash('v3.status', 'Data jumlah penerima berhasil dihapus.');
        $this->redirectRoute('v3.beneficiary-periods.index', navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();

        return view('livewire.v3.beneficiaries.periods.form', [
            ...$this->shellData($unit),
            'categories' => BeneficiaryCategory::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'destinationOptions' => $this->destinationOptions($unit->getKey()),
        ])->layout('layouts.v3', ['title' => $this->periodId ? 'Ubah Jumlah Penerima' : 'Input Jumlah Penerima']);
    }

    /** @return array<string, array{type: string, label: string}> */
    private function destinationOptions(int $unitId): array
    {
        $schools = School::query()
            ->where('sppg_unit_id', $unitId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (School $school): array => [
                "school:{$school->getKey()}" => ['type' => 'school', 'label' => $school->name],
            ]);
        $posyandus = Posyandu::query()
            ->where('sppg_unit_id', $unitId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Posyandu $posyandu): array => [
                "posyandu:{$posyandu->getKey()}" => ['type' => 'posyandu', 'label' => $posyandu->name],
            ]);

        return $schools->merge($posyandus)->all();
    }
}
