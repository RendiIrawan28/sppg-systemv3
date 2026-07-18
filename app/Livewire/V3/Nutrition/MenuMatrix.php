<?php

namespace App\Livewire\V3\Nutrition;

use App\Enums\MenuStatus;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\BeneficiaryPeriod;
use App\Models\Menu;
use App\Models\MenuCycle;
use App\Services\MenuCycleService;
use App\Services\MenuCycleWorkflowService;
use App\Services\MenuMatrixService;
use App\Services\NutritionistPlanningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

class MenuMatrix extends Component
{
    use InteractsWithV3Shell;

    #[Url(as: 'cycle', history: true)]
    public ?int $selectedCycleId = null;

    public ?int $planningPeriodId = null;

    public string $newCycleName = '';

    public string $newStartDate = '';

    public int $newCycleLength = 5;

    public float $bufferPercent = 2;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public string $pasteBuffer = '';

    public ?int $pasteStartDayNumber = 1;

    public string $cycleDecisionNotes = '';

    public ?string $actionMessage = null;

    public function mount(MenuMatrixService $matrix): void
    {
        $unit = $this->currentUnit();
        $this->authorizeWorkspace();

        $this->selectedCycleId ??= MenuCycle::query()->where('sppg_unit_id', $unit->getKey())->latest('start_date')->value('id');
        $cycle = $this->cycle();
        $this->planningPeriodId = $cycle?->beneficiary_period_id
            ?: BeneficiaryPeriod::query()->where('sppg_unit_id', $unit->getKey())->whereIn('status', ['approved', 'active', 'closed'])->latest('start_date')->value('id');
        $this->newStartDate = now()->startOfWeek()->toDateString();
        $this->newCycleLength = (int) config('nutrition_menu.default_cycle_length_days', 5);
        $this->bufferPercent = (float) ($cycle?->buffer_percent ?? config('nutrition_menu.default_buffer_percent', 2));
        $this->loadRows($matrix);
    }

    public function updatedSelectedCycleId(MenuMatrixService $matrix): void
    {
        $cycle = $this->cycle();
        if ($cycle) {
            $this->planningPeriodId = $cycle->beneficiary_period_id ?: $this->planningPeriodId;
            $this->bufferPercent = (float) $cycle->buffer_percent;
        }
        $this->loadRows($matrix);
    }

    public function createCycle(NutritionistPlanningService $service, MenuMatrixService $matrix): void
    {
        $this->authorizePermission('menus.create');
        $unit = $this->currentUnit();

        $data = $this->validate([
            'planningPeriodId' => ['required', 'integer'],
            'newCycleName' => ['required', 'string', 'max:255'],
            'newStartDate' => ['required', 'date'],
            'newCycleLength' => ['required', 'integer', 'min:1', 'max:60'],
            'bufferPercent' => ['required', 'numeric', 'min:0', 'max:20'],
        ], [
            'planningPeriodId.required' => 'Pilih periode penerima yang sudah disetujui.',
            'newCycleName.required' => 'Nama siklus wajib diisi.',
        ]);

        $this->runAction(function () use ($service, $matrix, $unit, $data): string {
            $cycle = $service->createCycle(
                unitId: $unit->getKey(), periodId: (int) $data['planningPeriodId'], name: $data['newCycleName'],
                startDate: $data['newStartDate'], cycleLength: (int) $data['newCycleLength'],
                bufferPercent: (float) $data['bufferPercent'], user: auth()->user(),
            );
            $this->selectedCycleId = $cycle->getKey();
            $this->newCycleName = '';
            $this->loadRows($matrix);

            return 'Siklus menu dibuat dan hari pelayanan sudah dibentuk.';
        });
    }

    public function refreshSnapshot(NutritionistPlanningService $service, MenuMatrixService $matrix): void
    {
        $this->authorizePermission('menus.update');
        $this->runAction(function () use ($service, $matrix): string {
            $cycle = $this->cycleOrFail();
            if (! $this->planningPeriodId) {
                throw ValidationException::withMessages(['period' => 'Pilih periode penerima terlebih dahulu.']);
            }
            $cycle->update(['beneficiary_period_id' => $this->planningPeriodId, 'buffer_percent' => $this->bufferPercent]);
            $service->refreshCycleSnapshot($cycle->refresh());
            $this->loadRows($matrix);

            return 'Porsi kecil, porsi besar, dan buffer berhasil dihitung ulang.';
        });
    }

    public function saveRow(int $dayId, MenuMatrixService $matrix): void
    {
        $this->runAction(function () use ($dayId, $matrix): string {
            $matrix->saveRow($this->cycleOrFail(), $dayId, $this->rows[$dayId] ?? [], auth()->user());
            $this->loadRows($matrix);

            return 'Baris menu berhasil disimpan.';
        });
    }

    public function saveAll(MenuMatrixService $matrix): void
    {
        $this->runAction(function () use ($matrix): string {
            $cycle = $this->cycleOrFail();
            DB::transaction(function () use ($matrix, $cycle): void {
                foreach ($this->rows as $dayId => $row) {
                    if (($row['can_edit'] ?? false) === true) {
                        $matrix->saveRow($cycle, (int) $dayId, $row, auth()->user());
                    }
                }
            });
            $this->loadRows($matrix);

            return 'Semua baris yang dapat diedit berhasil disimpan.';
        });
    }

    public function assignExisting(int $dayId, MenuMatrixService $matrix): void
    {
        $this->runAction(function () use ($dayId, $matrix): string {
            $matrix->assignExisting($this->cycleOrFail(), $dayId, (int) ($this->rows[$dayId]['selected_menu_id'] ?? 0), auth()->user());
            $this->loadRows($matrix);

            return 'Menu yang sudah ada berhasil digunakan.';
        });
    }

    public function copyToNextDay(int $dayId, MenuMatrixService $matrix): void
    {
        $this->runAction(function () use ($dayId, $matrix): string {
            $matrix->copyToNextDay($this->cycleOrFail(), $dayId, auth()->user());
            $this->loadRows($matrix);

            return 'Menu dipakai juga pada hari berikutnya.';
        });
    }

    public function duplicateMenu(int $dayId, MenuMatrixService $matrix): void
    {
        $this->runAction(function () use ($dayId, $matrix): string {
            $matrix->duplicate($this->cycleOrFail(), $dayId, auth()->user());
            $this->loadRows($matrix);

            return 'Salinan menu independen berhasil dibuat.';
        });
    }

    public function detachMenu(int $dayId, MenuMatrixService $matrix): void
    {
        $this->runAction(function () use ($dayId, $matrix): string {
            $matrix->detach($this->cycleOrFail(), $dayId, auth()->user());
            $this->loadRows($matrix);

            return 'Menu dilepas dari hari pelayanan.';
        });
    }

    public function pasteExcel(): void
    {
        $this->authorizePermission('menus.update');
        if (blank($this->pasteBuffer)) {
            $this->addError('pasteBuffer', 'Tempel data dari Excel terlebih dahulu.');

            return;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($this->pasteBuffer)) ?: [];
        $parsed = array_values(array_filter(array_map(static fn (string $line): array => array_map('trim', explode("\t", $line)), $lines), static fn (array $columns): bool => implode('', $columns) !== ''));
        if ($parsed !== [] && str_contains(strtolower((string) ($parsed[0][0] ?? '')), 'nama menu')) {
            array_shift($parsed);
        }

        $dayIds = collect($this->rows)->sortBy('day_number')->filter(fn (array $row): bool => (int) $row['day_number'] >= (int) ($this->pasteStartDayNumber ?: 1))->keys()->values();
        foreach ($parsed as $index => $columns) {
            $dayId = $dayIds->get($index);
            if (! $dayId || ! isset($this->rows[$dayId]) || ($this->rows[$dayId]['can_edit'] ?? false) !== true) {
                continue;
            }
            $this->rows[$dayId]['menu_name'] = $columns[0] ?? '';
            $this->rows[$dayId]['staple'] = $columns[1] ?? '';
            $this->rows[$dayId]['animal_protein'] = $columns[2] ?? '';
            $this->rows[$dayId]['plant_protein'] = $columns[3] ?? '';
            $this->rows[$dayId]['milk'] = $columns[4] ?? '';
            $this->rows[$dayId]['vegetables'] = str_replace([' | ', '|', '; '], "\n", $columns[5] ?? '');
            $this->rows[$dayId]['fruit'] = $columns[6] ?? '';
            $this->rows[$dayId]['notes'] = $columns[7] ?? '';
        }

        $this->actionMessage = 'Data Excel dimasukkan ke matriks. Periksa lalu tekan Simpan Semua.';
        $this->resetErrorBag('pasteBuffer');
    }

    public function submitCycle(MenuCycleWorkflowService $workflow, MenuMatrixService $matrix): void
    {
        $this->runWorkflow($workflow, $matrix, 'submit', 'Siklus berhasil divalidasi dan diajukan.');
    }

    public function approveCycle(MenuCycleWorkflowService $workflow, MenuMatrixService $matrix): void
    {
        $this->runWorkflow($workflow, $matrix, 'approve', 'Siklus berhasil disetujui dan dikunci.');
    }

    public function returnCycle(MenuCycleWorkflowService $workflow, MenuMatrixService $matrix): void
    {
        $this->runAction(function () use ($workflow, $matrix): string {
            $workflow->requestRevision($this->cycleOrFail(), $this->cycleDecisionNotes);
            $this->cycleDecisionNotes = '';
            $this->loadRows($matrix);

            return 'Siklus dikembalikan untuk revisi.';
        });
    }

    public function activateCycle(MenuCycleWorkflowService $workflow, MenuMatrixService $matrix): void
    {
        $this->runWorkflow($workflow, $matrix, 'activate', 'Siklus aktif dan siap dipakai oleh rencana distribusi.');
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $this->authorizeWorkspace();
        $cycle = $this->cycle();
        $planningSummary = null;

        if ($this->planningPeriodId) {
            $period = BeneficiaryPeriod::query()->where('sppg_unit_id', $unit->getKey())->whereIn('status', ['approved', 'active', 'closed'])->find($this->planningPeriodId);
            $planningSummary = $period ? app(NutritionistPlanningService::class)->summary($period, $this->bufferPercent) : null;
        }

        $menuOptions = Menu::query()->where('sppg_unit_id', $unit->getKey())->where('is_cycle_snapshot', false)
            ->whereIn('status', [MenuStatus::Draft->value, MenuStatus::RevisionRequired->value])
            ->when($this->selectedCycleId, fn ($query, $cycleId) => $query->where(function ($query) use ($cycleId): void {
                $query->whereDoesntHave('cycleDays')
                    ->orWhereHas('cycleDays', fn ($query) => $query->where('menu_cycle_id', $cycleId));
            }))
            ->orderByDesc('service_date')->orderBy('name')->limit(300)->get();

        return view('livewire.v3.nutrition.menu-matrix', [
            ...$this->shellData($unit),
            'cycle' => $cycle,
            'cycles' => MenuCycle::query()->where('sppg_unit_id', $unit->getKey())->orderByDesc('start_date')->get(),
            'periods' => BeneficiaryPeriod::query()->where('sppg_unit_id', $unit->getKey())->whereIn('status', ['approved', 'active', 'closed'])->orderByDesc('start_date')->get(),
            'planningSummary' => $planningSummary,
            'menuOptions' => $menuOptions,
            'menuEditorUrls' => $menuOptions->mapWithKeys(fn (Menu $menu): array => [$menu->getKey() => route('v3.nutrition.menus.show', $menu)])->all(),
            'readiness' => $this->readiness($cycle),
        ])->layout('layouts.v3', ['title' => 'Perencanaan Menu']);
    }

    public function loadRows(MenuMatrixService $matrix): void
    {
        $cycle = $this->cycle();
        $this->rows = $cycle ? $matrix->rows($cycle, auth()->user()) : [];
        $this->pasteStartDayNumber = collect($this->rows)->min('day_number') ?: 1;
    }

    private function cycle(): ?MenuCycle
    {
        if (! $this->selectedCycleId) {
            return null;
        }
        $unit = $this->currentUnit();

        return MenuCycle::query()->where('sppg_unit_id', $unit->getKey())->find($this->selectedCycleId);
    }

    private function cycleOrFail(): MenuCycle
    {
        return $this->cycle() ?? throw ValidationException::withMessages(['cycle' => 'Pilih siklus menu terlebih dahulu.']);
    }

    private function runWorkflow(MenuCycleWorkflowService $workflow, MenuMatrixService $matrix, string $method, string $message): void
    {
        $this->runAction(function () use ($workflow, $matrix, $method, $message): string {
            $workflow->{$method}($this->cycleOrFail());
            $this->loadRows($matrix);

            return $message;
        });
    }

    private function runAction(callable $callback): void
    {
        try {
            $this->actionMessage = $callback();
            $this->resetErrorBag();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError('action', implode(' ', $messages));
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('action', $exception->getMessage());
        }
    }

    private function authorizeWorkspace(): void
    {
        $user = auth()->user();
        abort_unless($user->is_super_admin || $user->can('menus.view') || $user->can('menus.update') || $user->can('menus.approve'), 403);
    }

    private function authorizePermission(string $permission): void
    {
        abort_unless(auth()->user()->is_super_admin || auth()->user()->can($permission), 403);
    }

    /** @return array{blocking: array<int, string>, warnings: array<int, string>} */
    private function readiness(?MenuCycle $cycle): array
    {
        if (! $cycle) {
            return ['blocking' => [], 'warnings' => []];
        }

        try {
            return app(MenuCycleService::class)->readinessReport($cycle);
        } catch (Throwable $exception) {
            report($exception);

            return ['blocking' => [$exception->getMessage()], 'warnings' => []];
        }
    }
}
