<?php

namespace App\Services;

use App\Models\AttendanceWorkSchedule;
use App\Models\AttendanceWorkScheduleAssignment;
use App\Models\Division;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceScheduleManager
{
    public function saveSchedule(int $unitId, array $data, User $actor, ?int $id = null): AttendanceWorkSchedule
    {
        return DB::transaction(function () use ($unitId, $data, $actor, $id) {
            $existing = $id ? AttendanceWorkSchedule::query()->where('sppg_unit_id', $unitId)->lockForUpdate()->findOrFail($id) : null;
            $data = Validator::make($data, [
                'division_id' => ['required', Rule::exists('divisions', 'id')->where('is_active', true)],
                'name' => ['required', 'string', 'max:120'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i'],
                'late_tolerance_minutes' => ['required', 'integer', 'between:0,180'],
                'work_days' => ['required', 'array', 'min:1'], 'work_days.*' => ['integer', 'between:1,7', 'distinct'],
                'is_default' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'],
                'effective_from' => ['required', 'date_format:Y-m-d', $existing ? Rule::in([$existing->effective_from?->toDateString() ?? now()->toDateString()]) : 'after_or_equal:today'],
                'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            ])->validate();
            $data['work_days'] = array_map('intval', $data['work_days']);
            $data['effective_until'] = $data['effective_until'] ?: null;
            $versioned = $existing && ($existing->effective_from?->toDateString() ?? '0001-01-01') < now()->toDateString();
            if ($versioned) {
                if (($data['effective_until'] && $data['effective_until'] < now()->toDateString())
                    || ($existing->effective_until && $existing->effective_until->lt(today()))) {
                    throw ValidationException::withMessages(['effective_until' => 'Jadwal yang sudah berakhir tetap menjadi riwayat. Buat jadwal baru untuk perubahan berikutnya.']);
                }
                $data['effective_from'] = now()->toDateString();
            }
            Division::query()->whereKey($data['division_id'])->lockForUpdate()->firstOrFail();
            if ($existing && (int) $existing->division_id !== (int) $data['division_id'] && $existing->assignments()->exists()) {
                throw ValidationException::withMessages(['division_id' => 'Jadwal sudah memiliki penugasan pegawai. Buat jadwal baru bila divisinya berbeda.']);
            }
            if ($data['is_active'] && $data['is_default']) {
                $others = AttendanceWorkSchedule::query()->where('sppg_unit_id', $unitId)->where('division_id', $data['division_id'])
                    ->where('is_active', true)->where('is_default', true)->when($id, fn ($q) => $q->where('id', '!=', $id))->get();
                foreach ($others as $other) {
                    if ($this->overlaps($data['effective_from'], $data['effective_until'], $other->effective_from?->toDateString(), $other->effective_until?->toDateString())
                        && array_intersect($data['work_days'], $other->work_days ?? [])) {
                        throw ValidationException::withMessages(['work_days' => 'Hari kerja berbenturan dengan jadwal utama '.$other->name.'. Pilih hari atau periode yang berbeda.']);
                    }
                }
            }
            if ($versioned) {
                $existing->update(['effective_until' => today()->subDay()->toDateString(), 'updated_by' => $actor->id]);
            }
            $schedule = $existing && ! $versioned ? $existing : new AttendanceWorkSchedule;
            $schedule->fill([...$data, 'sppg_unit_id' => $unitId, 'updated_by' => $actor->id]);
            if (! $existing || $versioned) {
                $schedule->created_by = $actor->id;
            }
            $schedule->save();
            if ($versioned) {
                // Retain yesterday's assignment for offline taps; use the revised shift from today.
                foreach ($existing->assignments()->where('is_active', true)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', today()))->lockForUpdate()->get() as $assignment) {
                    $until = $assignment->effective_until?->toDateString();
                    if ($assignment->effective_from->lt(today())) {
                        $assignment->update(['effective_until' => today()->subDay()->toDateString(), 'updated_by' => $actor->id]);
                        AttendanceWorkScheduleAssignment::create([
                            ...$assignment->only(['sppg_unit_id', 'user_id', 'is_active', 'notes']),
                            'attendance_work_schedule_id' => $schedule->id, 'effective_from' => today()->toDateString(),
                            'effective_until' => $until, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                        ]);
                    } else {
                        $assignment->update(['attendance_work_schedule_id' => $schedule->id, 'updated_by' => $actor->id]);
                    }
                }
            }

            return $schedule;
        });
    }

    public function saveAssignment(int $unitId, array $data, User $actor, ?int $id = null): AttendanceWorkScheduleAssignment
    {
        return DB::transaction(function () use ($unitId, $data, $actor, $id) {
            $existing = $id ? AttendanceWorkScheduleAssignment::query()->where('sppg_unit_id', $unitId)->lockForUpdate()->findOrFail($id) : null;
            $data = Validator::make($data, [
                'user_id' => ['required', Rule::exists('users', 'id')->where('is_active', true)],
                'attendance_work_schedule_id' => ['required', Rule::exists('attendance_work_schedules', 'id')->where('sppg_unit_id', $unitId)->when(! $existing || ($data['is_active'] ?? true), fn ($rule) => $rule->where('is_active', true))],
                'effective_from' => ['required', 'date_format:Y-m-d', $existing ? Rule::in([$existing->effective_from->toDateString()]) : 'after_or_equal:today'],
                'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
                'is_active' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
            ])->validate();
            $data['effective_until'] = $data['effective_until'] ?: null;
            $user = User::query()->whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            if ($existing && (int) $existing->user_id !== (int) $user->id) {
                throw ValidationException::withMessages(['user_id' => 'Penugasan tidak dapat dipindahkan ke pegawai lain. Buat penugasan baru.']);
            }
            $versioned = $existing && $existing->effective_from->lt(today());
            if ($versioned) {
                if (($data['effective_until'] && $data['effective_until'] < now()->toDateString())
                    || ($existing->effective_until && $existing->effective_until->lt(today()))) {
                    throw ValidationException::withMessages(['effective_until' => 'Penugasan sudah berakhir. Buat penugasan baru untuk periode berikutnya.']);
                }
                $data['effective_from'] = now()->toDateString();
            }
            $schedule = AttendanceWorkSchedule::query()->findOrFail($data['attendance_work_schedule_id']);
            if (! $user->divisions()->wherePivot('sppg_unit_id', $unitId)->wherePivot('is_active', true)->where('divisions.is_active', true)->where('divisions.id', $schedule->division_id)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Pegawai harus menjadi anggota aktif divisi jadwal pada unit ini.']);
            }
            if ($data['is_active']) {
                $others = AttendanceWorkScheduleAssignment::query()->where('sppg_unit_id', $unitId)->where('user_id', $user->id)
                    ->where('is_active', true)->when($id, fn ($q) => $q->where('id', '!=', $id))->get();
                foreach ($others as $other) {
                    if ($this->overlaps($data['effective_from'], $data['effective_until'], $other->effective_from->toDateString(), $other->effective_until?->toDateString())) {
                        throw ValidationException::withMessages(['effective_from' => 'Pegawai sudah memiliki shift khusus pada periode tersebut. Akhiri penugasan lama sebelum menambah yang baru.']);
                    }
                }
            }
            if ($versioned) {
                $existing->update(['effective_until' => today()->subDay()->toDateString(), 'updated_by' => $actor->id]);
            }
            $assignment = $existing && ! $versioned ? $existing : new AttendanceWorkScheduleAssignment;
            $assignment->fill([...$data, 'sppg_unit_id' => $unitId, 'updated_by' => $actor->id]);
            if (! $existing || $versioned) {
                $assignment->created_by = $actor->id;
            }
            $assignment->save();

            return $assignment;
        });
    }

    private function overlaps(?string $from, ?string $until, ?string $otherFrom, ?string $otherUntil): bool
    {
        return ($from ?? '0001-01-01') <= ($otherUntil ?? '9999-12-31') && ($otherFrom ?? '0001-01-01') <= ($until ?? '9999-12-31');
    }
}
