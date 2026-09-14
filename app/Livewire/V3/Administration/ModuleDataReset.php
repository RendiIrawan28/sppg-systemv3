<?php

namespace App\Livewire\V3\Administration;

use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\TestDataCleanupLog;
use App\Services\ModuleDataResetService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ModuleDataReset extends Component
{
    use InteractsWithV3Shell;

    public string $scope = ModuleDataResetService::WAREHOUSE;

    public string $reason = '';

    public string $confirmation = '';

    public string $password = '';

    public bool $backupConfirmed = false;

    public ?string $actionMessage = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
        $this->currentUnit();
    }

    public function updatedScope(): void
    {
        $this->reset('reason', 'confirmation', 'password', 'backupConfirmed', 'actionMessage');
        $this->resetErrorBag();
    }

    public function resetModule(ModuleDataResetService $service): void
    {
        $actor = auth()->user();
        abort_unless($actor?->is_super_admin, 403);
        $this->validate([
            'scope' => ['required', Rule::in(array_keys(ModuleDataResetService::LABELS))],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['required', Rule::in([$this->requiredConfirmation()])],
            'password' => ['required', 'string'],
            'backupConfirmed' => ['accepted'],
        ], [
            'confirmation.in' => 'Ketik '.$this->requiredConfirmation().' dengan tepat.',
            'backupConfirmed.accepted' => 'Konfirmasi bahwa cadangan database sudah tersedia.',
        ]);

        $attemptKey = 'module-reset:'.$actor->getKey().':'.request()->ip();
        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            throw ValidationException::withMessages(['password' => 'Terlalu banyak percobaan. Coba kembali dalam beberapa menit.']);
        }
        if (! Hash::check($this->password, $actor->password)) {
            RateLimiter::hit($attemptKey, 300);
            throw ValidationException::withMessages(['password' => 'Kata sandi akun tidak sesuai.']);
        }
        RateLimiter::clear($attemptKey);

        $deleted = $service->reset($this->scope, $this->currentUnit()->getKey(), $actor, $this->reason);
        $this->actionMessage = ModuleDataResetService::LABELS[$this->scope].' berhasil direset. '.array_sum($deleted).' baris dihapus; catatan audit tetap disimpan.';
        $this->reset('reason', 'confirmation', 'password', 'backupConfirmed');
    }

    public function requiredConfirmation(): string
    {
        return $this->scope === ModuleDataResetService::BENEFICIARIES ? 'RESET PENERIMA' : 'RESET GUDANG';
    }

    public function render(ModuleDataResetService $service)
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
        $unit = $this->currentUnit();
        $preview = $service->preview($this->scope, $unit->getKey());

        return view('livewire.v3.administration.module-data-reset', [
            ...$this->shellData($unit),
            'labels' => ModuleDataResetService::LABELS,
            'preview' => $preview,
            'recentLogs' => TestDataCleanupLog::query()
                ->where('sppg_unit_id', $unit->getKey())
                ->whereIn('record_type', ['module-reset-warehouse', 'module-reset-beneficiaries'])
                ->latest('deleted_at')
                ->limit(10)
                ->get(),
        ])->layout('layouts.v3', ['title' => 'Reset Data Modul']);
    }
}
