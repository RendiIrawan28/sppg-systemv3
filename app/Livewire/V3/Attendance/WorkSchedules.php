<?php

namespace App\Livewire\V3\Attendance;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\AttendanceWorkSchedule;
use App\Models\AttendanceWorkScheduleAssignment;
use App\Models\Division;
use App\Models\User;
use App\Services\AttendanceScheduleManager;
use Livewire\Component;

class WorkSchedules extends Component
{
    use InteractsWithV3Shell;

    public ?int $scheduleId = null;

    public ?int $assignmentId = null;

    public string $filterDivisionId = '';

    public string $filterStatus = 'active';

    public array $form = [];

    public array $assignment = [];

    public ?string $actionMessage = null;

    public function mount(): void
    {
        $this->authorizeSchedules();
        $this->resetForms();
    }

    public function resetForms(): void
    {
        $this->authorizeSchedules();
        $this->scheduleId = null;
        $this->assignmentId = null;
        $this->form = ['division_id' => '', 'name' => '', 'start_time' => '', 'end_time' => '', 'late_tolerance_minutes' => 0, 'work_days' => [], 'is_default' => true, 'is_active' => true, 'effective_from' => now()->toDateString(), 'effective_until' => ''];
        $this->assignment = ['user_id' => '', 'attendance_work_schedule_id' => '', 'effective_from' => now()->toDateString(), 'effective_until' => '', 'is_active' => true, 'notes' => ''];
        $this->resetErrorBag();
    }

    public function editSchedule(int $id): void
    {
        $this->authorizeSchedules();
        $schedule = AttendanceWorkSchedule::query()->where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
        $this->scheduleId = $id;
        $this->form = $schedule->only(array_keys($this->form));
        $this->form['start_time'] = substr($schedule->start_time, 0, 5);
        $this->form['end_time'] = substr($schedule->end_time, 0, 5);
        $this->form['effective_from'] = $schedule->effective_from?->toDateString() ?? now()->toDateString();
        $this->form['effective_until'] = $schedule->effective_until?->toDateString() ?? '';
        $this->resetErrorBag();
    }

    public function saveSchedule(AttendanceScheduleManager $manager): void
    {
        $this->authorizeSchedules();
        $manager->saveSchedule($this->currentUnit()->id, $this->form, auth()->user(), $this->scheduleId);
        $this->resetForms();
        $this->actionMessage = 'Jam kerja berhasil disimpan. Riwayat presensi yang sudah tercatat tetap dipertahankan.';
    }

    public function editAssignment(int $id): void
    {
        $this->authorizeSchedules();
        $assignment = AttendanceWorkScheduleAssignment::query()->where('sppg_unit_id', $this->currentUnit()->id)->findOrFail($id);
        $this->assignmentId = $id;
        $this->assignment = $assignment->only(array_keys($this->assignment));
        $this->assignment['effective_from'] = $assignment->effective_from->toDateString();
        $this->assignment['effective_until'] = $assignment->effective_until?->toDateString() ?? '';
        $this->assignment['notes'] = $assignment->notes ?? '';
        $this->resetErrorBag();
    }

    public function saveAssignment(AttendanceScheduleManager $manager): void
    {
        $this->authorizeSchedules();
        $manager->saveAssignment($this->currentUnit()->id, $this->assignment, auth()->user(), $this->assignmentId);
        $this->resetForms();
        $this->actionMessage = 'Shift khusus pegawai berhasil disimpan.';
    }

    private function authorizeSchedules(): void
    {
        abort_unless($this->allowed('attendance.schedules'), 403);
    }

    public function render()
    {
        $this->authorizeSchedules();
        $unit = $this->currentUnit();

        return view('livewire.v3.attendance.work-schedules', [
            ...$this->shellData($unit),
            'divisions' => Division::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'schedules' => AttendanceWorkSchedule::query()->where('sppg_unit_id', $unit->id)->with('division')
                ->when($this->filterDivisionId !== '', fn ($q) => $q->where('division_id', $this->filterDivisionId))
                ->when($this->filterStatus === 'active', fn ($q) => $q->where('is_active', true)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', today())))
                ->when($this->filterStatus === 'inactive', fn ($q) => $q->where(fn ($q) => $q->where('is_active', false)->orWhereDate('effective_until', '<', today())))
                ->orderBy('division_id')->orderBy('name')->get(),
            'availableSchedules' => AttendanceWorkSchedule::query()->where('sppg_unit_id', $unit->id)
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', today())))
                    ->when($this->assignmentId, fn ($q) => $q->orWhere('id', $this->assignment['attendance_work_schedule_id'])))
                ->with('division')->orderBy('name')->get(),
            'assignments' => AttendanceWorkScheduleAssignment::query()->where('sppg_unit_id', $unit->id)->with(['user', 'workSchedule.division'])->orderByDesc('effective_from')->get(),
            'users' => User::query()->where('is_active', true)->whereHas('divisions', fn ($q) => $q->where('division_user.sppg_unit_id', $unit->id)->where('division_user.is_active', true)->where('divisions.is_active', true))->orderBy('name')->get(),
            'dayNames' => [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'],
        ])->layout('layouts.v3', ['title' => 'Jam Kerja & Shift']);
    }
}
