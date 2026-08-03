<?php

namespace App\Livewire\V3\Attendance;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\AttendanceDevice;
use App\Models\AttendanceRegistrationSession;
use App\Models\AttendanceSession;
use App\Models\AttendanceTap;
use App\Models\User;
use App\Services\VolunteerAttendanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use InteractsWithV3Shell;

    public string $activeTab = 'attendance';

    #[Url(as: 'tanggal')]
    public string $filterDate = '';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $sessionId = null;

    public ?int $manualUserId = null;

    public string $manualWorkDate = '';

    public string $manualCheckIn = '';

    public string $manualCheckOut = '';

    public string $manualStatus = 'present';

    public string $manualNotes = '';

    public string $manualReason = '';

    public string $deviceName = '';

    public string $deviceCode = '';

    public string $deviceLocation = '';

    public ?string $revealedDeviceKey = null;

    public ?string $revealedDeviceCode = null;

    public ?int $registrationUserId = null;

    public ?int $registrationDeviceId = null;

    public ?string $actionMessage = null;

    public bool $showResetPanel = false;

    public string $resetReason = '';

    public string $resetConfirmation = '';

    public function mount(): void
    {
        abort_unless($this->allowed('attendance.view'), 403);
        $this->filterDate = $this->filterDate ?: now()->toDateString();
        $this->manualWorkDate = $this->filterDate;
    }

    public function editSession(int $id): void
    {
        abort_unless($this->allowed('attendance.correct'), 403);
        $session = AttendanceSession::query()->where('sppg_unit_id', $this->currentUnit()->getKey())->findOrFail($id);
        $this->sessionId = $session->getKey();
        $this->manualUserId = $session->user_id;
        $this->manualWorkDate = $session->work_date->toDateString();
        $this->manualCheckIn = $session->check_in_at?->format('H:i') ?? '';
        $this->manualCheckOut = $session->check_out_at?->format('H:i') ?? '';
        $this->manualStatus = $session->status;
        $this->manualNotes = (string) $session->notes;
        $this->manualReason = '';
        $this->activeTab = 'correction';
    }

    public function saveManual(VolunteerAttendanceService $attendance): void
    {
        abort_unless($this->allowed('attendance.correct'), 403);
        $data = $this->validate([
            'manualUserId' => ['required', Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'manualWorkDate' => ['required', 'date'],
            'manualStatus' => ['required', Rule::in(['present', 'permission', 'sick', 'absent'])],
            'manualCheckIn' => [$this->manualStatus === 'present' ? 'required' : 'nullable', 'date_format:H:i'],
            'manualCheckOut' => ['nullable', 'date_format:H:i'],
            'manualNotes' => ['nullable', 'string', 'max:2000'],
            'manualReason' => ['required', 'string', 'max:1000'],
        ], [], [
            'manualUserId' => 'relawan', 'manualWorkDate' => 'tanggal kerja', 'manualStatus' => 'status',
            'manualCheckIn' => 'jam masuk', 'manualCheckOut' => 'jam pulang', 'manualNotes' => 'catatan', 'manualReason' => 'alasan koreksi',
        ]);

        $checkIn = null;
        $checkOut = null;
        if ($data['manualStatus'] === 'present') {
            $checkIn = Carbon::parse("{$data['manualWorkDate']} {$data['manualCheckIn']}");
            if (filled($data['manualCheckOut'])) {
                $checkOut = Carbon::parse("{$data['manualWorkDate']} {$data['manualCheckOut']}");
                if ($checkOut->lt($checkIn)) {
                    $checkOut->addDay();
                }
            }
        }

        $session = $this->sessionId
            ? AttendanceSession::query()->where('sppg_unit_id', $this->currentUnit()->getKey())->findOrFail($this->sessionId)
            : null;
        $user = User::query()->findOrFail($data['manualUserId']);
        $attendance->saveManual(
            unitId: $this->currentUnit()->getKey(),
            user: $user,
            attributes: [
                'work_date' => $data['manualWorkDate'],
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'status' => $data['manualStatus'],
                'notes' => trim($data['manualNotes']) ?: null,
            ],
            actor: auth()->user(),
            reason: $data['manualReason'],
            session: $session,
        );

        $this->actionMessage = $session ? 'Data presensi berhasil dikoreksi.' : 'Data presensi manual berhasil ditambahkan.';
        $this->resetManualForm();
        $this->activeTab = 'attendance';
    }

    public function createDevice(): void
    {
        abort_unless($this->allowed('attendance.devices'), 403);
        $data = $this->validate([
            'deviceName' => ['required', 'string', 'max:120'],
            'deviceCode' => ['required', 'alpha_dash', 'max:50', Rule::unique('attendance_devices', 'code')],
            'deviceLocation' => ['nullable', 'string', 'max:180'],
        ], [], ['deviceName' => 'nama perangkat', 'deviceCode' => 'kode perangkat', 'deviceLocation' => 'lokasi']);

        $key = Str::random(40);
        $device = AttendanceDevice::query()->create([
            'sppg_unit_id' => $this->currentUnit()->getKey(),
            'name' => trim($data['deviceName']),
            'code' => strtoupper(trim($data['deviceCode'])),
            'secret_hash' => hash('sha256', $key),
            'location' => trim($data['deviceLocation']) ?: null,
            'is_active' => true,
        ]);

        $this->revealedDeviceCode = $device->code;
        $this->revealedDeviceKey = $key;
        $this->reset('deviceName', 'deviceCode', 'deviceLocation');
        $this->actionMessage = 'Perangkat dibuat. Salin kode dan kunci ke firmware sebelum meninggalkan halaman.';
    }

    public function rotateDeviceKey(int $id): void
    {
        abort_unless($this->allowed('attendance.devices'), 403);
        $device = AttendanceDevice::query()->where('sppg_unit_id', $this->currentUnit()->getKey())->findOrFail($id);
        $key = Str::random(40);
        $device->update(['secret_hash' => hash('sha256', $key)]);
        $this->revealedDeviceCode = $device->code;
        $this->revealedDeviceKey = $key;
        $this->actionMessage = 'Kunci perangkat diperbarui. Kunci lama langsung tidak berlaku.';
    }

    public function toggleDevice(int $id): void
    {
        abort_unless($this->allowed('attendance.devices'), 403);
        $device = AttendanceDevice::query()->where('sppg_unit_id', $this->currentUnit()->getKey())->findOrFail($id);
        $device->update(['is_active' => ! $device->is_active]);
        $this->actionMessage = $device->is_active ? 'Perangkat diaktifkan.' : 'Perangkat dinonaktifkan.';
    }

    public function startRegistration(): void
    {
        abort_unless($this->allowed('attendance.manage'), 403);
        $data = $this->validate([
            'registrationUserId' => ['required', Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'registrationDeviceId' => ['required', Rule::exists('attendance_devices', 'id')->where(fn ($query) => $query->where('sppg_unit_id', $this->currentUnit()->getKey())->where('is_active', true))],
        ], [], ['registrationUserId' => 'relawan', 'registrationDeviceId' => 'perangkat']);

        AttendanceRegistrationSession::query()
            ->where('attendance_device_id', $data['registrationDeviceId'])
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        AttendanceRegistrationSession::query()->create([
            'sppg_unit_id' => $this->currentUnit()->getKey(),
            'attendance_device_id' => $data['registrationDeviceId'],
            'user_id' => $data['registrationUserId'],
            'initiated_by' => auth()->id(),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(2),
        ]);

        $this->actionMessage = 'Mode pendaftaran aktif selama dua menit. Tempelkan kartu pada perangkat yang dipilih.';
    }

    public function cancelRegistration(int $id): void
    {
        abort_unless($this->allowed('attendance.manage'), 403);
        AttendanceRegistrationSession::query()->where('sppg_unit_id', $this->currentUnit()->getKey())->where('status', 'pending')->findOrFail($id)->update(['status' => 'cancelled']);
        $this->actionMessage = 'Pendaftaran kartu dibatalkan.';
    }

    public function openResetPanel(): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        $this->reset('resetReason', 'resetConfirmation');
        $this->resetErrorBag();
        $this->showResetPanel = true;
    }

    public function resetAttendance(): void
    {
        abort_unless(auth()->user()->is_super_admin, 403);

        $data = $this->validate([
            'filterDate' => ['required', 'date'],
            'resetReason' => ['required', 'string', 'max:1000'],
            'resetConfirmation' => ['required', 'in:RESET'],
        ], [
            'resetConfirmation.in' => 'Ketik RESET untuk mengonfirmasi.',
        ], [
            'filterDate' => 'tanggal presensi',
            'resetReason' => 'alasan reset',
            'resetConfirmation' => 'konfirmasi reset',
        ]);

        $count = DB::transaction(function () use ($data): int {
            $sessions = AttendanceSession::query()
                ->where('sppg_unit_id', $this->currentUnit()->getKey())
                ->whereDate('work_date', $data['filterDate'])
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $session->forceFill([
                    'deleted_by' => auth()->id(),
                    'deletion_reason' => trim($data['resetReason']),
                ])->save();
                $session->delete();
            }

            return $sessions->count();
        });

        $this->showResetPanel = false;
        $this->reset('resetReason', 'resetConfirmation');
        $this->actionMessage = "{$count} data presensi tanggal ".Carbon::parse($data['filterDate'])->format('d/m/Y').' berhasil direset. Riwayat tap RFID tetap tersimpan.';
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $sessionsQuery = AttendanceSession::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->whereDate('work_date', $this->filterDate)
            ->with(['user.roles', 'user.divisions', 'checkInDevice', 'checkOutDevice'])
            ->when(trim($this->search) !== '', fn ($query) => $query->whereHas('user', fn ($query) => $query->where('name', 'like', '%'.trim($this->search).'%')->orWhere('employee_number', 'like', '%'.trim($this->search).'%')));

        $summaryQuery = clone $sessionsQuery;
        $sessions = $sessionsQuery->latest('check_in_at')->latest('id')->get();
        $summarySessions = $summaryQuery->get();
        $devices = AttendanceDevice::query()->where('sppg_unit_id', $unit->getKey())->latest()->get();
        $activeRegistration = AttendanceRegistrationSession::query()
            ->where('sppg_unit_id', $unit->getKey())->where('status', 'pending')->where('expires_at', '>', now())
            ->with(['user', 'device'])->latest()->first();

        return view('livewire.v3.attendance.index', [
            ...$this->shellData($unit),
            'sessions' => $sessions,
            'summary' => [
                'present' => $summarySessions->where('status', 'present')->pluck('user_id')->unique()->count(),
                'working' => $summarySessions->where('status', 'present')->whereNull('check_out_at')->count(),
                'finished' => $summarySessions->where('status', 'present')->whereNotNull('check_out_at')->count(),
                'other' => $summarySessions->whereIn('status', ['permission', 'sick', 'absent'])->count(),
            ],
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_number']),
            'devices' => $devices,
            'activeRegistration' => $activeRegistration,
            'recentTaps' => AttendanceTap::query()->where('sppg_unit_id', $unit->getKey())->with(['user', 'device'])->latest('received_at')->limit(20)->get(),
            'canCorrect' => $this->allowed('attendance.correct'),
            'canManage' => $this->allowed('attendance.manage'),
            'canDevices' => $this->allowed('attendance.devices'),
            'canExport' => $this->allowed('attendance.export'),
            'canReset' => auth()->user()->is_super_admin,
        ])->layout('layouts.v3', ['title' => 'Presensi Relawan']);
    }

    private function resetManualForm(): void
    {
        $this->sessionId = null;
        $this->manualUserId = null;
        $this->manualWorkDate = $this->filterDate;
        $this->manualCheckIn = '';
        $this->manualCheckOut = '';
        $this->manualStatus = 'present';
        $this->manualNotes = '';
        $this->manualReason = '';
        $this->resetErrorBag();
    }
}
