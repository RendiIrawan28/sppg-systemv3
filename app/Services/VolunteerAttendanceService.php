<?php

namespace App\Services;

use App\Models\AttendanceDevice;
use App\Models\AttendanceRegistrationSession;
use App\Models\AttendanceSession;
use App\Models\AttendanceSessionHistory;
use App\Models\AttendanceTap;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VolunteerAttendanceService
{
    public const DUPLICATE_SECONDS = 60;

    public const MINIMUM_WORK_HOURS = 4;

    public const REENTRY_WAIT_HOURS = 6;

    public static function normalizeUid(?string $uid): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $uid));
    }

    /** @return array<string, mixed> */
    public function configuration(AttendanceDevice $device): array
    {
        $registration = $this->pendingRegistration($device);

        return [
            'status' => 'success',
            'mode' => $registration ? 'registrasi' : 'presensi',
            'pegawai' => $registration?->user?->name,
            'registration_expires_at' => $registration?->expires_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function recordTap(
        AttendanceDevice $device,
        string $uid,
        string $requestId,
        ?Carbon $tappedAt = null,
        bool $offline = false,
    ): array {
        $uid = self::normalizeUid($uid);
        if ($uid === '') {
            throw ValidationException::withMessages(['uid_kartu' => 'UID kartu tidak valid.']);
        }

        $existing = AttendanceTap::query()
            ->where('attendance_device_id', $device->getKey())
            ->where('request_id', $requestId)
            ->first();
        if ($existing) {
            return $existing->response_payload ?? [];
        }

        // The RFID device sends offline timestamps as UTC (ISO-8601 with a Z
        // suffix). Convert the instant to the application's local timezone
        // before persisting it to MySQL DATETIME columns, which do not retain
        // timezone information.
        $eventAt = ($tappedAt?->copy() ?? now())->setTimezone(config('app.timezone'));
        if ($eventAt->isAfter(now()->addMinutes(5)) || $eventAt->isBefore(now()->subDays(7))) {
            $eventAt = now();
            $offline = false;
        }

        return DB::transaction(function () use ($device, $uid, $requestId, $eventAt, $offline): array {
            if ($registration = $this->pendingRegistration($device, lock: true)) {
                return $this->completeRegistration($device, $registration, $uid, $requestId, $eventAt, $offline);
            }

            $user = User::query()
                ->where('is_active', true)
                ->whereRaw("UPPER(REPLACE(REPLACE(employee_number, ' ', ''), '-', '')) = ?", [$uid])
                ->first();

            if (! $user) {
                return $this->storeTap($device, null, null, $uid, $requestId, 'uid_not_found', 'rejected', 'Kartu tidak terdaftar.', $eventAt, $offline, [
                    'status' => 'error',
                    'action' => 'uid_not_found',
                    'pegawai' => '',
                    'message' => 'Kartu tidak terdaftar.',
                ]);
            }

            $latest = AttendanceSession::query()
                ->where('sppg_unit_id', $device->sppg_unit_id)
                ->where('user_id', $user->getKey())
                ->where('status', 'present')
                ->latest('check_in_at')
                ->lockForUpdate()
                ->first();

            if ($latest && $latest->check_out_at === null) {
                if ($latest->check_in_at && $latest->check_in_at->diffInSeconds($eventAt, false) <= self::DUPLICATE_SECONDS) {
                    return $this->storeTap($device, $user, $latest, $uid, $requestId, 'duplicate_tap', 'ignored', 'Kartu baru saja terbaca.', $eventAt, $offline, [
                        'status' => 'success',
                        'action' => 'duplicate_tap',
                        'pegawai' => $user->name,
                        'message' => 'Kartu baru saja terbaca.',
                    ]);
                }

                $checkoutAllowedAt = $latest->check_in_at?->copy()->addHours(self::MINIMUM_WORK_HOURS);
                if ($checkoutAllowedAt && $eventAt->lt($checkoutAllowedAt)) {
                    $minutes = max(1, (int) ceil($eventAt->diffInSeconds($checkoutAllowedAt) / 60));

                    return $this->storeTap($device, $user, $latest, $uid, $requestId, 'wait_4_hours', 'blocked', "Tunggu {$minutes} menit sebelum pulang.", $eventAt, $offline, [
                        'status' => 'success',
                        'action' => 'wait_4_hours',
                        'pegawai' => $user->name,
                        'message' => "Belum memenuhi minimal 4 jam kerja. Tunggu {$minutes} menit sebelum presensi pulang.",
                        'remaining_minutes' => $minutes,
                    ]);
                }

                $latest->update(['check_out_at' => $eventAt, 'check_out_device_id' => $device->getKey()]);

                return $this->storeTap($device, $user, $latest, $uid, $requestId, 'check_out', 'success', 'Presensi pulang berhasil.', $eventAt, $offline, [
                    'status' => 'success',
                    'action' => 'check_out',
                    'pegawai' => $user->name,
                    'message' => 'Presensi pulang berhasil.',
                    'recorded_at' => $eventAt->toIso8601String(),
                ]);
            }

            if ($latest?->check_out_at) {
                $allowedAt = $latest->check_out_at->copy()->addHours(self::REENTRY_WAIT_HOURS);
                if ($eventAt->lt($allowedAt)) {
                    $minutes = max(1, (int) ceil($eventAt->diffInSeconds($allowedAt) / 60));

                    return $this->storeTap($device, $user, $latest, $uid, $requestId, 'wait_6_hours', 'blocked', "Tunggu {$minutes} menit.", $eventAt, $offline, [
                        'status' => 'success',
                        'action' => 'wait_6_hours',
                        'pegawai' => $user->name,
                        'message' => "Tunggu {$minutes} menit sebelum masuk kembali.",
                        'remaining_minutes' => $minutes,
                    ]);
                }
            }

            $session = AttendanceSession::query()->create([
                'sppg_unit_id' => $device->sppg_unit_id,
                'user_id' => $user->getKey(),
                'work_date' => $eventAt->toDateString(),
                'check_in_at' => $eventAt,
                'check_in_device_id' => $device->getKey(),
                'source' => $offline ? 'rfid_offline' : 'rfid',
                'status' => 'present',
            ]);

            return $this->storeTap($device, $user, $session, $uid, $requestId, 'check_in', 'success', 'Presensi masuk berhasil.', $eventAt, $offline, [
                'status' => 'success',
                'action' => 'check_in',
                'pegawai' => $user->name,
                'message' => 'Presensi masuk berhasil.',
                'recorded_at' => $eventAt->toIso8601String(),
            ]);
        });
    }

    public function saveManual(
        int $unitId,
        User $user,
        array $attributes,
        User $actor,
        string $reason,
        ?AttendanceSession $session = null,
    ): AttendanceSession {
        return DB::transaction(function () use ($unitId, $user, $attributes, $actor, $reason, $session): AttendanceSession {
            $before = $session?->only(['user_id', 'work_date', 'check_in_at', 'check_out_at', 'status', 'notes']);
            $session ??= new AttendanceSession;
            $session->fill([
                ...$attributes,
                'sppg_unit_id' => $unitId,
                'user_id' => $user->getKey(),
                'source' => 'manual',
                'corrected_by' => $actor->getKey(),
                'corrected_at' => now(),
            ])->save();

            AttendanceSessionHistory::query()->create([
                'attendance_session_id' => $session->getKey(),
                'actor_id' => $actor->getKey(),
                'action' => $before ? 'corrected' : 'created_manual',
                'before_data' => $before,
                'after_data' => $session->only(['user_id', 'work_date', 'check_in_at', 'check_out_at', 'status', 'notes']),
                'reason' => trim($reason),
            ]);

            return $session->refresh();
        });
    }

    private function pendingRegistration(AttendanceDevice $device, bool $lock = false): ?AttendanceRegistrationSession
    {
        AttendanceRegistrationSession::query()
            ->where('attendance_device_id', $device->getKey())
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $query = AttendanceRegistrationSession::query()
            ->with('user')
            ->where('attendance_device_id', $device->getKey())
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** @return array<string, mixed> */
    private function completeRegistration(AttendanceDevice $device, AttendanceRegistrationSession $registration, string $uid, string $requestId, Carbon $eventAt, bool $offline): array
    {
        $usedBy = User::query()->where('id', '!=', $registration->user_id)
            ->whereRaw("UPPER(REPLACE(REPLACE(employee_number, ' ', ''), '-', '')) = ?", [$uid])->first();
        if ($usedBy) {
            return $this->storeTap($device, $registration->user, null, $uid, $requestId, 'already_registered', 'rejected', 'Kartu sudah digunakan akun lain.', $eventAt, $offline, [
                'status' => 'already_registered', 'action' => 'already_registered', 'pegawai' => $registration->user->name, 'message' => 'Kartu sudah digunakan akun lain.',
            ]);
        }

        $registration->user->update(['employee_number' => $uid]);
        $registration->update(['status' => 'completed', 'completed_at' => now(), 'registered_uid' => $uid]);

        return $this->storeTap($device, $registration->user, null, $uid, $requestId, 'register_card', 'success', 'Kartu berhasil didaftarkan.', $eventAt, $offline, [
            'status' => 'success', 'action' => 'register_card', 'pegawai' => $registration->user->name, 'message' => 'Kartu berhasil didaftarkan.',
        ]);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function storeTap(AttendanceDevice $device, ?User $user, ?AttendanceSession $session, string $uid, string $requestId, string $action, string $result, string $message, Carbon $eventAt, bool $offline, array $payload): array
    {
        AttendanceTap::query()->create([
            'sppg_unit_id' => $device->sppg_unit_id,
            'attendance_device_id' => $device->getKey(),
            'user_id' => $user?->getKey(),
            'attendance_session_id' => $session?->getKey(),
            'request_id' => $requestId,
            'uid_snapshot' => $uid,
            'action' => $action,
            'result' => $result,
            'response_message' => $message,
            'tapped_at' => $eventAt,
            'received_at' => now(),
            'is_offline' => $offline,
            'response_payload' => $payload,
        ]);

        return $payload;
    }
}
