<?php

namespace App\Livewire\V3\Security;

use App\Enums\SecuritySituation;
use App\Enums\UserRole;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\FieldIncident;
use App\Models\MobileDeviceToken;
use App\Models\SecurityShift;
use App\Services\Mobile\FcmHttpV1Client;
use App\Services\SecurityMonitoringService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class Index extends Component
{
    use InteractsWithV3Shell;
    use WithFileUploads;

    public string $situation = 'safe';

    public bool $gateSecure = true;

    public bool $perimeterSecure = true;

    public string $accessActivity = '';

    public string $visitorActivity = '';

    public string $notes = '';

    public ?TemporaryUploadedFile $reportPhoto = null;

    public ?string $actionMessage = null;

    #[Url(as: 'tanggal')]
    public string $historyDate = '';

    #[Url(as: 'petugas')]
    public string $historyOfficer = '';

    public function mount(): void
    {
        abort_unless($this->allowed('security.view'), 403);
    }

    public function startShift(): void
    {
        abort_unless($this->allowed('security.create'), 403);

        $this->runAction(function (): string {
            app(SecurityMonitoringService::class)->startShift($this->currentUnit(), auth()->user());

            return 'Shift 12 jam dimulai. Laporan pertama diingatkan setelah tiga jam.';
        });
    }

    public function submitReport(): void
    {
        abort_unless($this->allowed('security.create'), 403);

        $this->runAction(function (): string {
            $this->validate([
                'situation' => ['required', 'in:safe,attention,emergency'],
                'gateSecure' => ['boolean'],
                'perimeterSecure' => ['boolean'],
                'accessActivity' => ['nullable', 'string', 'max:5000'],
                'visitorActivity' => ['nullable', 'string', 'max:5000'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'reportPhoto' => ['required', 'image', 'max:5120'],
            ]);

            $shift = $this->activeShift();
            if (! $shift) {
                throw ValidationException::withMessages(['shift' => 'Tidak ada shift keamanan aktif.']);
            }

            $path = $this->reportPhoto?->store('v3/security/reports', 'public');
            try {
                app(SecurityMonitoringService::class)->submitReport($shift, auth()->user(), [
                    'situation' => $this->situation,
                    'gate_secure' => $this->gateSecure,
                    'perimeter_secure' => $this->perimeterSecure,
                    'access_activity' => trim($this->accessActivity) ?: null,
                    'visitor_activity' => trim($this->visitorActivity) ?: null,
                    'notes' => trim($this->notes) ?: null,
                    'photo_path' => $path,
                ]);
            } catch (Throwable $exception) {
                if ($path) {
                    Storage::disk('public')->delete($path);
                }

                throw $exception;
            }

            $this->resetReportForm();

            return 'Laporan situasi keamanan berhasil dicatat.';
        });
    }

    public function render()
    {
        $unit = $this->currentUnit();
        $canWrite = $this->allowed('security.create');
        if ($canWrite) {
            app(SecurityMonitoringService::class)->expireOverdueShifts($unit->getKey(), auth()->id());
        }
        $activeShift = $canWrite ? $this->activeShift()?->load('reports') : null;
        $nextDueAt = $activeShift?->next_report_due_at;
        $reportDue = $activeShift?->isReportDue() ?? false;

        $shiftQuery = SecurityShift::query()
            ->where('sppg_unit_id', $unit->getKey());
        if (auth()->user()->hasRole(UserRole::Satpam->value) && ! auth()->user()->is_super_admin) {
            $shiftQuery->where('officer_id', auth()->id());
        }

        $officerOptions = (clone $shiftQuery)
            ->select(['officer_id', 'officer_name_snapshot'])
            ->orderBy('officer_name_snapshot')
            ->get()
            ->unique('officer_id')
            ->values();

        $recentShifts = $shiftQuery
            ->when($this->historyDate, fn ($query) => $query->whereDate('started_at', $this->historyDate))
            ->when($this->historyOfficer, fn ($query) => $query->where('officer_id', $this->historyOfficer))
            ->withCount('reports')
            ->with(['reports' => fn ($query) => $query->latest('reported_at')])
            ->latest('started_at')
            ->limit(12)
            ->get();

        $incidents = FieldIncident::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->where('division_code', 'security')
            ->latest('occurred_at')
            ->limit(10)
            ->get();

        $firebaseStatus = app(FcmHttpV1Client::class)->configurationStatus();
        $hasActiveDevice = $canWrite && MobileDeviceToken::query()
            ->where('user_id', auth()->id())
            ->active()
            ->exists();

        return view('livewire.v3.security.index', [
            ...$this->shellData($unit),
            'activeShift' => $activeShift,
            'recentShifts' => $recentShifts,
            'officerOptions' => $officerOptions,
            'incidents' => $incidents,
            'nextDueAt' => $nextDueAt,
            'reportDue' => $reportDue,
            'canWrite' => $canWrite,
            'situationOptions' => SecuritySituation::options(),
            'notificationReady' => $firebaseStatus['configured'] && $hasActiveDevice,
            'notificationMessage' => ! $firebaseStatus['configured']
                ? 'Pengingat aplikasi belum aktif karena layanan notifikasi server belum siap.'
                : ($hasActiveDevice
                    ? 'Perangkat aktif dan siap menerima pengingat laporan.'
                    : 'Belum ada perangkat aktif. Masuk ke aplikasi Android pada perangkat Satpam untuk mengaktifkan pengingat.'),
        ])->layout('layouts.v3', ['title' => 'Keamanan']);
    }

    private function activeShift(): ?SecurityShift
    {
        return SecurityShift::query()
            ->where('sppg_unit_id', $this->currentUnit()->getKey())
            ->where('officer_id', auth()->id())
            ->active()
            ->latest('started_at')
            ->first();
    }

    private function resetReportForm(): void
    {
        $this->situation = 'safe';
        $this->gateSecure = true;
        $this->perimeterSecure = true;
        $this->accessActivity = '';
        $this->visitorActivity = '';
        $this->notes = '';
        $this->reportPhoto = null;
    }

    private function runAction(callable $callback): void
    {
        try {
            $this->actionMessage = $callback();
            $this->resetErrorBag();
        } catch (Throwable $exception) {
            report($exception);
            $message = $exception instanceof ValidationException
                ? collect($exception->errors())->flatten()->implode(' ')
                : $exception->getMessage();
            $this->addError('action', $message);
        }
    }
}
