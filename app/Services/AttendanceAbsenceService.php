<?php

namespace App\Services;

use App\Models\AttendanceSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceAbsenceService
{
    public function __construct(private AttendanceWorkScheduleResolver $resolver) {}

    public function markAbsentForCompletedSchedules(?Carbon $asOf = null): int
    {
        $asOf = ($asOf?->copy() ?? now())->setTimezone(config('app.timezone'));
        $created = 0;
        $memberships = DB::table('division_user')->join('divisions', 'divisions.id', '=', 'division_user.division_id')
            ->join('users', 'users.id', '=', 'division_user.user_id')
            ->where('division_user.is_active', true)->where('divisions.is_active', true)->where('users.is_active', true)
            ->select('division_user.sppg_unit_id', 'division_user.user_id')->distinct()->get();
        foreach ($memberships as $member) {
            foreach ([$asOf->copy()->subDay(), $asOf->copy()] as $day) {
                $created += DB::transaction(function () use ($member, $day, $asOf): int {
                    $user = User::query()->where('is_active', true)->lockForUpdate()->find($member->user_id);
                    if (! $user || ! ($schedule = $this->resolver->resolveForUserAndWorkDate($user, (int) $member->sppg_unit_id, $day)) || $schedule->endsAt->gt($asOf)) {
                        return 0;
                    }
                    // Preserve intentional super-admin resets instead of recreating deleted attendance.
                    if (AttendanceSession::withTrashed()->where('sppg_unit_id', $member->sppg_unit_id)->where('user_id', $user->id)->whereDate('work_date', $schedule->workDate)->exists()) {
                        return 0;
                    }
                    AttendanceSession::query()->create([
                        ...$schedule->snapshot(), 'sppg_unit_id' => $member->sppg_unit_id, 'user_id' => $user->id,
                        'work_date' => $schedule->workDate, 'status' => 'absent', 'source' => 'system_absence',
                        'notes' => 'Tidak ada presensi setelah jadwal kerja selesai. Dicatat otomatis oleh sistem.',
                    ]);

                    return 1;
                });
            }
        }

        return $created;
    }
}
