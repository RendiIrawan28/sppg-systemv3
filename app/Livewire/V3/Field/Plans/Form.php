<?php

namespace App\Livewire\V3\Field\Plans;

use App\Enums\NutritionRecordStatus;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\FieldDistributionPlan;
use App\Models\MenuCycleDay;
use App\Services\FieldDistributionPlanWorkflow;
use App\Services\FieldPlanActualConfirmationService;
use App\Services\NutritionRequirementFromFieldPlanService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class Form extends Component
{
    use InteractsWithV3Shell;

    public ?int $planId = null;

    public string $distributionDate = '';

    public string $menuCycleDayId = '';

    public string $confirmationDeadlineAt = '';

    public string $generalNotes = '';

    public string $workflowNotes = '';

    public float $bufferPercent = 3;

    public ?string $actionMessage = null;

    /** @var array<int, array<string, mixed>> */
    public array $destinations = [];

    public function mount(?FieldDistributionPlan $plan = null): void
    {
        abort_unless($this->allowed($plan?->exists ? 'field_planning.view' : 'field_planning.create'), 403);
        if ($plan?->exists) {
            abort_unless((int) $plan->sppg_unit_id === (int) $this->currentUnit()->getKey(), 404);
            $this->planId = $plan->getKey();
            $this->fillFromPlan($plan);
        } else {
            $this->distributionDate = now()->addDays(3)->toDateString();
            $this->confirmationDeadlineAt = now()->addDay()->format('Y-m-d\TH:i');
        }
    }

    public function save(): void
    {
        $this->runAction(function (): string {
            $created = ! $this->planId;
            $plan = $this->persist();
            if ($created) {
                app(FieldPlanActualConfirmationService::class)->synchronize($plan, auth()->user());
                $this->planId = $plan->getKey();
            }
            $this->fillFromPlan($plan->refresh());

            return $created ? 'Rencana dibuat dan penerima periode otomatis dimuat.' : 'Rencana lapangan berhasil disimpan.';
        });
    }

    public function refreshBeneficiaries(): void
    {
        $this->runAction(function (): string {
            $plan = $this->persist();
            $result = app(FieldPlanActualConfirmationService::class)->synchronize($plan, auth()->user());
            $this->fillFromPlan($plan->refresh());

            return sprintf('Penerima diperbarui: %d tujuan dan %d penerima.', $result['destination_count'], $result['confirmed_beneficiaries']);
        });
    }

    public function checkReadiness(): void
    {
        $this->runAction(function (): string {
            $issues = app(FieldDistributionPlanWorkflow::class)->submissionIssues($this->persist());

            return $issues === [] ? 'Rencana siap diaktifkan.' : implode(' ', $issues);
        });
    }

    public function activate(): void
    {
        $this->runAction(function (): string {
            app(FieldDistributionPlanWorkflow::class)->submit($this->persist(), auth()->user(), trim($this->workflowNotes) ?: null);
            $this->fillFromPlan($this->plan()->refresh());

            return 'Rencana aktif';
        });
    }

    public function generateRequirement(): void
    {
        $this->runAction(function (): string {
            $requirement = app(NutritionRequirementFromFieldPlanService::class)->generate($this->plan(), auth()->user(), $this->bufferPercent);

            return "Kebutuhan {$requirement->plan_number} dihitung dan draft pengadaan otomatis disinkronkan.";
        });
    }

    public function complete(): void
    {
        $this->runAction(function (): string {
            app(FieldDistributionPlanWorkflow::class)->complete($this->plan(), auth()->user(), trim($this->workflowNotes) ?: null);
            $this->fillFromPlan($this->plan()->refresh());

            return 'Rencana ditandai selesai dan laporan harian telah dibentuk.';
        });
    }

    public function delete(): void
    {
        abort_unless($this->allowed('field_planning.delete'), 403);
        $plan = $this->plan();
        abort_unless($plan->canBeDeleted(), 403);
        $plan->delete();
        session()->flash('v3.status', 'Rencana lapangan berhasil dihapus.');
        $this->redirectRoute('v3.field.plans.index', navigate: true);
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('field_planning.view') || ! $this->planId, 403);
        $plan = $this->planId ? $this->plan()->load(['processingBatch', 'portioningSession', 'distributionRun']) : null;
        $cycleDays = MenuCycleDay::query()->with(['cycle', 'menu'])
            ->whereNotNull('menu_id')
            ->whereHas('cycle', fn ($query) => $query->where('sppg_unit_id', $unit->getKey())->whereIn('status', [NutritionRecordStatus::Approved->value, NutritionRecordStatus::Active->value]))
            ->whereDate('delivery_date', '>=', now()->subDays(7))->orderBy('delivery_date')->limit(120)->get();

        return view('livewire.v3.field.plans.form', [
            ...$this->shellData($unit), 'plan' => $plan, 'cycleDays' => $cycleDays,
            'editable' => ! $plan || $plan->isEditable(),
            'canUpdate' => $this->allowed('field_planning.update'),
            'canSubmit' => $this->allowed('field_planning.submit'),
        ])->layout('layouts.v3', ['title' => $plan ? 'Rincian Rencana Lapangan' : 'Tambah Rencana Lapangan']);
    }

    private function persist(): FieldDistributionPlan
    {
        $plan = $this->planId ? $this->plan() : new FieldDistributionPlan;
        abort_unless($this->allowed($this->planId ? 'field_planning.update' : 'field_planning.create'), 403);
        abort_unless(! $plan->exists || $plan->isEditable(), 403);
        $data = $this->validate([
            'distributionDate' => ['required', 'date'], 'menuCycleDayId' => ['required', 'integer'],
            'confirmationDeadlineAt' => ['nullable', 'date'],
            'generalNotes' => ['nullable', 'string', 'max:5000'],
            'destinations' => ['array'], 'destinations.*.route_name' => ['nullable', 'string', 'max:255'],
            'destinations.*.sequence_order' => ['nullable', 'integer', 'min:1'],
            'destinations.*.special_notes' => ['nullable', 'string', 'max:2000'],
            'destinations.*.groups' => ['array'], 'destinations.*.groups.*.confirmed_beneficiaries' => ['required', 'integer', 'min:0'],
            'destinations.*.groups.*.menu_audience' => ['required', 'string', 'max:100'],
            'destinations.*.groups.*.portion_size' => ['required', 'in:small,large'],
            'destinations.*.groups.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $cycleDay = MenuCycleDay::query()->with(['cycle', 'menu'])->whereKey($data['menuCycleDayId'])
            ->whereNotNull('menu_id')->whereHas('cycle', fn ($query) => $query->where('sppg_unit_id', $this->currentUnit()->getKey())->whereIn('status', [NutritionRecordStatus::Approved->value, NutritionRecordStatus::Active->value]))->firstOrFail();
        $deliveryDate = $cycleDay->delivery_date?->toDateString() ?: $cycleDay->service_date?->toDateString();
        if ($deliveryDate !== $data['distributionDate']) {
            throw ValidationException::withMessages(['menuCycleDayId' => 'Menu siklus tidak sesuai dengan tanggal distribusi.']);
        }

        return DB::transaction(function () use ($plan, $data, $cycleDay): FieldDistributionPlan {
            try {
                $period = app(FieldPlanActualConfirmationService::class)->readyPeriod($this->currentUnit()->getKey(), $cycleDay->service_date?->toDateString() ?: $data['distributionDate']);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['distributionDate' => $exception->getMessage()]);
            }
            $plan->fill([
                'sppg_unit_id' => $this->currentUnit()->getKey(), 'beneficiary_period_id' => $period->getKey(),
                'menu_cycle_day_id' => $cycleDay->getKey(), 'distribution_date' => $data['distributionDate'],
                'service_date' => $cycleDay->service_date?->toDateString() ?: $data['distributionDate'],
                'production_date' => $cycleDay->production_date?->toDateString() ?: $cycleDay->delivery_date?->toDateString() ?: $data['distributionDate'],
                'is_rapel' => (bool) $cycleDay->is_rapel, 'menu_id' => $cycleDay->menu_id,
                'menu_name_snapshot' => $cycleDay->menu?->name ?: 'Menu Siklus', 'meal_type' => 'lunch',
                'shift' => $plan->shift ?: 'morning', 'confirmation_deadline_at' => $data['confirmationDeadlineAt'] ?: null,
                'general_notes' => trim((string) $data['generalNotes']) ?: null, 'updated_by' => auth()->id(),
                'source_system' => 'web_v3',
            ]);
            if (! $plan->exists) {
                $plan->created_by = auth()->id();
            }
            $plan->save();

            foreach ($data['destinations'] as $destinationId => $row) {
                $destination = $plan->destinations()->whereKey($destinationId)->first();
                if (! $destination) {
                    continue;
                }
                $destination->update([
                    'route_name' => trim((string) ($row['route_name'] ?? '')) ?: null,
                    'sequence_order' => (int) ($row['sequence_order'] ?? 1),
                    'special_notes' => trim((string) ($row['special_notes'] ?? '')) ?: null,
                ]);
                foreach ($row['groups'] ?? [] as $groupId => $groupRow) {
                    $group = $destination->recipientGroups()->whereKey($groupId)->first();
                    $group?->update([
                        'confirmed_beneficiaries' => (int) $groupRow['confirmed_beneficiaries'],
                        'menu_audience' => $groupRow['menu_audience'], 'portion_size' => $groupRow['portion_size'],
                        'notes' => trim((string) ($groupRow['notes'] ?? '')) ?: null,
                    ]);
                }
                $destination->recalculatePortionsFromGroups();
            }
            $plan->recalculateTotals();

            return $plan->refresh();
        });
    }

    private function fillFromPlan(FieldDistributionPlan $plan): void
    {
        $plan->load('destinations.recipientGroups');
        $this->distributionDate = $plan->distribution_date?->toDateString() ?? '';
        $this->menuCycleDayId = (string) $plan->menu_cycle_day_id;
        $this->confirmationDeadlineAt = $plan->confirmation_deadline_at?->format('Y-m-d\TH:i') ?? '';
        $this->generalNotes = (string) $plan->general_notes;
        $this->destinations = $plan->destinations->mapWithKeys(fn ($destination): array => [$destination->id => [
            'name' => $destination->destination_name_snapshot, 'type' => $destination->destination_type,
            'address' => $destination->address_snapshot, 'contact' => $destination->contact_name_snapshot,
            'route_name' => (string) $destination->route_name, 'sequence_order' => (int) $destination->sequence_order,
            'special_notes' => (string) $destination->special_notes,
            'registered' => (int) $destination->registered_beneficiaries, 'confirmed' => (int) $destination->confirmed_beneficiaries,
            'small' => (int) $destination->small_portions, 'large' => (int) $destination->large_portions,
            'groups' => $destination->recipientGroups->mapWithKeys(fn ($group): array => [$group->id => [
                'name' => $group->beneficiary_category_name_snapshot, 'registered_beneficiaries' => (int) $group->registered_beneficiaries,
                'confirmed_beneficiaries' => (int) $group->confirmed_beneficiaries, 'menu_audience' => (string) $group->menu_audience,
                'portion_size' => (string) $group->portion_size, 'notes' => (string) $group->notes,
            ]])->all(),
        ]])->all();
    }

    private function plan(): FieldDistributionPlan
    {
        return FieldDistributionPlan::query()->where('sppg_unit_id', $this->currentUnit()->getKey())->findOrFail($this->planId);
    }

    private function runAction(callable $callback): void
    {
        try {
            $this->actionMessage = $callback();
            $this->resetErrorBag();
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('action', $exception instanceof ValidationException ? collect($exception->errors())->flatten()->implode(' ') : $exception->getMessage());
        }
    }
}
