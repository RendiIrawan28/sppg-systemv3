<?php

namespace App\Livewire\V3\Concerns;

use App\Enums\UserRole;
use App\Models\SppgUnit;
use App\Support\V3\Navigation;
use App\Support\V3\UnitContext;
use Illuminate\Support\Facades\Auth;

trait InteractsWithV3Shell
{
    protected function allowed(string $permission): bool
    {
        $user = Auth::user();

        return $user->is_super_admin || $user->can($permission);
    }

    protected function currentUnit(): SppgUnit
    {
        $unit = app(UnitContext::class)->for(Auth::user());

        abort_unless($unit instanceof SppgUnit, 403, 'Akun belum memiliki unit SPPG aktif.');

        app(UnitContext::class)->activate(Auth::user(), $unit);

        return $unit;
    }

    /** @return array<string, mixed> */
    protected function shellData(SppgUnit $unit): array
    {
        $user = Auth::user();
        $role = $user->roles->sortBy(
            static fn ($role): int => UserRole::sortOrderFor($role->name),
        )->first();

        return [
            'unit' => $unit,
            'navigation' => app(Navigation::class)->for($user, $unit),
            'roleLabel' => UserRole::labelFor($role?->name),
        ];
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        $this->redirectRoute('login', navigate: true);
    }
}
